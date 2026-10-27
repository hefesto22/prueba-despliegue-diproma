<?php

namespace Tests\Feature\Filament\Pages;

use App\Enums\CashMovementType;
use App\Filament\Actions\SpecialInvoiceAction;
use App\Filament\Pages\PointOfSale;
use App\Models\CaiRange;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\CustomPermissionsSeeder;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * POS — botón "Factura casos fuera de manejo de sistema" y atomicidad
 * venta + factura del carrito.
 *
 * La lógica fiscal de las líneas libres ya está cubierta en
 * SaleServiceFreeLineTest; aquí solo la orquestación UI → services.
 */
class PointOfSaleSpecialInvoiceTest extends TestCase
{
    use CreatesMatriz, RefreshDatabase;

    private User $superAdmin;

    /** @var \Closure(): void */
    private \Closure $undoRepeaterFake;

    protected function setUp(): void
    {
        parent::setUp();

        // Igual que producción: super_admin NO pasa por Gate::before
        // (define_via_gate = false), solo puede lo que tiene asignado.
        // CustomPermissionsSeeder es quien le da Issue:SpecialInvoice.
        Permission::firstOrCreate(['name' => 'View:PointOfSale', 'guard_name' => 'web']);
        $this->seed(CustomPermissionsSeeder::class);
        Role::findByName(Utils::getSuperAdminName(), 'web')->givePermissionTo('View:PointOfSale');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->superAdmin = User::factory()->create([
            'is_active' => true,
            'default_establishment_id' => $this->matriz->id,
        ]);
        $this->superAdmin->assignRole(Utils::getSuperAdminName());

        app(CashSessionService::class)->open(
            establishmentId: $this->matriz->id,
            openedBy: $this->superAdmin,
            openingAmount: 1000.00,
        );

        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => null,
        ]);

        // Keys predecibles en el Repeater para poder pasar las líneas como lista.
        $this->undoRepeaterFake = Repeater::fake();
    }

    protected function tearDown(): void
    {
        ($this->undoRepeaterFake)();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function datosEpson(string $rtn = '0501-1990-123456'): array
    {
        return [
            'customer_rtn' => $rtn,
            'customer_name' => 'Taller López',
            'payment_method' => 'efectivo',
            'lines' => [
                ['description' => 'Almohadillas y mantenimiento de epson L350', 'quantity' => 1, 'unit_cost' => 300, 'has_isv' => false, 'unit_price' => 1200],
                ['description' => 'Mantenimiento de rodos y limpieza epson L210', 'quantity' => 1, 'unit_cost' => null, 'has_isv' => false, 'unit_price' => 980],
                ['description' => 'Mantenimiento general - reseteo epson L3110', 'quantity' => 1, 'unit_cost' => null, 'has_isv' => true, 'unit_price' => 1050],
            ],
        ];
    }

    public function test_super_admin_ve_el_boton(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(PointOfSale::class)
            ->assertActionVisible(SpecialInvoiceAction::NAME);
    }

    public function test_cajero_con_acceso_al_pos_no_ve_el_boton(): void
    {
        Permission::firstOrCreate(['name' => 'View:PointOfSale', 'guard_name' => 'web']);
        $cajeroRole = Role::firstOrCreate(['name' => 'cajero', 'guard_name' => 'web']);
        $cajeroRole->givePermissionTo('View:PointOfSale');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $cajero = User::factory()->create([
            'is_active' => true,
            'default_establishment_id' => $this->matriz->id,
        ]);
        $cajero->assignRole($cajeroRole);

        $this->actingAs($cajero);

        Livewire::test(PointOfSale::class)
            ->assertActionHidden(SpecialInvoiceAction::NAME);
    }

    public function test_emite_venta_y_factura_cai_desde_el_modal(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(PointOfSale::class)
            ->callAction(SpecialInvoiceAction::NAME, data: $this->datosEpson())
            ->assertHasNoActionErrors()
            ->assertNotified('Factura emitida');

        $sale = Sale::query()->sole();
        $this->assertSame('3230.00', $sale->total);
        $this->assertSame('TALLER LÓPEZ', $sale->customer_name);
        $this->assertSame(3, $sale->items()->whereNull('product_id')->count());

        $invoice = Invoice::query()->where('sale_id', $sale->id)->sole();
        $this->assertNotNull($invoice->cai);
        $this->assertSame('3230.00', $invoice->total);
    }

    public function test_rtn_incompleto_marca_error_y_no_factura(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(PointOfSale::class)
            ->callAction(SpecialInvoiceAction::NAME, data: $this->datosEpson(rtn: '0501-1990'))
            ->assertHasActionErrors(['customer_rtn' => 'regex']);

        $this->assertSame(0, Sale::count());
    }

    public function test_si_falla_el_cai_no_queda_venta_ni_ingreso_en_caja(): void
    {
        CaiRange::query()->delete();
        $this->actingAs($this->superAdmin);

        Livewire::test(PointOfSale::class)
            ->callAction(SpecialInvoiceAction::NAME, data: $this->datosEpson())
            ->assertNotified('No se pudo emitir la factura');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, CashMovement::query()->where('type', CashMovementType::SaleIncome)->count());
    }

    /**
     * Deuda resuelta: antes la venta del carrito se confirmaba en su propia
     * transacción y la factura en otra. Sin CAI la venta quedaba cobrada y
     * con stock descontado, el carrito seguía lleno y reintentar la duplicaba.
     */
    public function test_pos_no_deja_venta_si_falla_la_factura(): void
    {
        CaiRange::query()->delete();
        $this->actingAs($this->superAdmin);

        $product = Product::factory()->brandNew()->inCategory(Category::factory()->create())->create([
            'sale_price' => 1000,
            'cost_price' => 800,
            'stock' => 10,
        ]);

        Livewire::test(PointOfSale::class)
            ->set('cart', [[
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit_price' => (float) $product->sale_price_with_isv,
                'tax_type' => $product->tax_type->value,
                'quantity' => 2,
                'stock' => $product->stock,
                'is_service' => false,
                'detail' => '',
            ]])
            ->call('processSale')
            ->assertNotified('Error al procesar')
            ->assertSet('cart', fn (array $cart): bool => count($cart) === 1);

        $this->assertSame(0, Sale::count());
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(0, CashMovement::query()->where('type', CashMovementType::SaleIncome)->count());
    }
}

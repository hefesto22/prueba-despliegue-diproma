<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Products;

use App\Enums\MovementType;
use App\Enums\ProductCondition;
use App\Enums\ProductType;
use App\Enums\TaxType;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Formulario guiado de Productos (rediseño 2026-09-30):
 *   - El tipo se elige con botones; "Otro tipo…" abre el buscador de tipos
 *     personalizados y el valor guardado sigue siendo product_type.
 *   - Stock y alerta mínima empiezan vacíos y vacío se guarda como 0.
 *   - El resumen muestra nombre, desglose del precio con ISV y ganancia.
 */
class ProductGuidedFormTest extends TestCase
{
    use CreatesMatriz, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Gate::before(fn ($user) => $user instanceof User && $user->hasRole(Utils::getSuperAdminName()) ? true : null);

        $admin = User::factory()->create([
            'is_active' => true,
            'default_establishment_id' => $this->matriz->id,
        ]);
        $admin->assignRole(Utils::getSuperAdminName());
        $this->actingAs($admin);
    }

    // ─── Tipo de producto ────────────────────────────────────

    public function test_el_tipo_elegido_con_boton_es_el_que_se_guarda(): void
    {
        Livewire::test(CreateProduct::class)
            ->assertFormFieldHidden('product_type')
            ->fillForm([
                'type_choice' => ProductType::Monitor->value,
                'brand' => 'dell',
                'model' => 'p2422h',
                'cost_price' => 2000,
                'sale_price' => 2875,
                'stock' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $monitor = Product::query()->latest('id')->firstOrFail();
        $this->assertSame(ProductType::Monitor->value, $monitor->product_type);
        $this->assertSame('MONITOR DELL P2422H', $monitor->name);
        $this->assertStringStartsWith('MON-DEL-', $monitor->sku);
        $this->assertSame(2, $monitor->stock);
        // El precio se escribe con ISV y se guarda sin él.
        $this->assertEquals(2500.00, round((float) $monitor->sale_price, 2));
    }

    public function test_otro_tipo_abre_el_buscador_y_guarda_el_tipo_personalizado(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['type_choice' => 'otro'])
            ->assertFormFieldVisible('product_type')
            ->assertFormSet(['product_type' => null])
            ->fillForm([
                'product_type' => 'HONORARIOS',
                'is_service' => true,
                'tax_type' => TaxType::Exento->value,
                'cost_price' => 0,
                'sale_price' => 0,
            ])
            ->assertFormFieldHidden('stock')
            ->call('create')
            ->assertHasNoFormErrors();

        $servicio = Product::query()->latest('id')->firstOrFail();
        $this->assertSame('HONORARIOS', $servicio->product_type);
        $this->assertTrue($servicio->is_service);
        $this->assertSame(999999, $servicio->stock, 'Un servicio lleva stock infinito.');
    }

    public function test_otro_tipo_sin_elegir_tipo_no_se_guarda(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'type_choice' => 'otro',
                'cost_price' => 100,
                'sale_price' => 150,
            ])
            ->call('create')
            ->assertHasFormErrors(['product_type' => 'required']);

        $this->assertSame(0, Product::count());
    }

    public function test_buscar_un_tipo_que_tiene_boton_marca_ese_boton(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['type_choice' => 'otro'])
            ->fillForm(['product_type' => ProductType::Tablet->value])
            ->assertFormSet(['type_choice' => ProductType::Tablet->value])
            ->assertFormFieldVisible('spec_tablet_screen');
    }

    public function test_cambiar_de_tipo_limpia_los_specs_del_anterior(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['spec_laptop_ram' => '16 GB'])
            ->fillForm(['type_choice' => ProductType::Desktop->value])
            ->fillForm(['type_choice' => ProductType::Laptop->value])
            ->assertFormSet(['spec_laptop_ram' => null]);
    }

    // ─── Inventario ──────────────────────────────────────────

    public function test_stock_y_alerta_empiezan_vacios_y_vacio_se_guarda_en_cero(): void
    {
        Livewire::test(CreateProduct::class)
            ->assertFormSet(['stock' => null, 'min_stock' => null])
            ->fillForm([
                'type_choice' => ProductType::Accessory->value,
                'cost_price' => 100,
                'sale_price' => 172.50,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $accesorio = Product::query()->latest('id')->firstOrFail();
        $this->assertSame(0, $accesorio->stock);
        $this->assertSame(0, $accesorio->min_stock);
        $this->assertSame(0, InventoryMovement::count(), 'Sin stock inicial no hay carga en el Kardex.');
    }

    public function test_el_stock_inicial_entra_al_kardex(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'type_choice' => ProductType::Laptop->value,
                'condition' => ProductCondition::Used->value,
                'brand' => 'HP',
                'cost_price' => 6000,
                'sale_price' => 8500,
                'stock' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $laptop = Product::query()->latest('id')->firstOrFail();
        $this->assertSame(TaxType::Exento, $laptop->tax_type);
        $this->assertEquals(8500.00, round((float) $laptop->sale_price, 2), 'Usado: exento, sin quitar ISV.');
        $this->assertSame(1, InventoryMovement::query()
            ->where('product_id', $laptop->id)
            ->where('type', MovementType::AjusteEntrada->value)
            ->count());
    }

    // ─── Editar ──────────────────────────────────────────────

    public function test_al_editar_se_marca_el_boton_del_tipo_guardado(): void
    {
        $laptop = Product::factory()->create(['product_type' => ProductType::Laptop->value]);

        Livewire::test(EditProduct::class, ['record' => $laptop->getRouteKey()])
            ->assertFormSet(['type_choice' => ProductType::Laptop->value])
            ->assertFormFieldHidden('product_type');
    }

    public function test_al_editar_un_tipo_personalizado_se_marca_otro_tipo(): void
    {
        $camara = Product::factory()->create(['product_type' => 'EQUIPO DE SEGURIDAD']);

        Livewire::test(EditProduct::class, ['record' => $camara->getRouteKey()])
            ->assertFormSet(['type_choice' => 'otro', 'product_type' => 'EQUIPO DE SEGURIDAD'])
            ->assertFormFieldVisible('product_type')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('EQUIPO DE SEGURIDAD', $camara->fresh()->product_type);
    }

    public function test_editar_conserva_el_sku(): void
    {
        $laptop = Product::factory()->create(['product_type' => ProductType::Laptop->value, 'brand' => 'HP']);
        $sku = $laptop->sku;

        Livewire::test(EditProduct::class, ['record' => $laptop->getRouteKey()])
            ->fillForm(['model' => 'ELITEBOOK 840'])
            ->assertSee($sku)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($sku, $laptop->fresh()->sku);
        $this->assertStringContainsString('ELITEBOOK 840', $laptop->fresh()->name);
    }

    // ─── Resumen ─────────────────────────────────────────────

    public function test_el_resumen_muestra_nombre_desglose_y_ganancia(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'type_choice' => ProductType::Laptop->value,
                'brand' => 'HP',
                'model' => 'PROBOOK 450',
                'cost_price' => 1000,
                'sale_price' => 1437.50,
            ])
            ->assertSee('LAPTOP HP PROBOOK 450')
            ->assertSee('LAP-HP-XXXXX')
            ->assertSee('L 1,250.00')
            ->assertSee('L 187.50')
            ->assertSee('L 250.00 (25%)')
            ->assertDontSee('Name preview')
            ->assertDontSee('Price summary');
    }

    public function test_el_resumen_avisa_si_el_precio_queda_bajo_el_costo(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'cost_price' => 1000,
                'sale_price' => 1100,
            ])
            ->assertSee('Revise el precio:');
    }
}

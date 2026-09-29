<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Purchases;

use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Filament\Resources\Purchases\Pages\ViewPurchase;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Formulario de Compras como documento fiscal (Fase 1):
 *   - Los montos se transcriben del documento y el servidor deriva subtotal/total.
 *   - Reglas de montos: bloquea lo imposible, NO bloquea diferencias de ISV.
 *   - Regresión: editar un Recibo Interno ya no le borra el proveedor real.
 */
class PurchaseDocumentFormTest extends TestCase
{
    use CreatesMatriz, RefreshDatabase;

    private User $admin;

    private Supplier $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        // Bypass de Gate igual que CreatePurchaseReciboInternoTest: el foco es
        // el flujo del formulario, no los permisos finos.
        Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Gate::before(fn ($user) => $user instanceof User && $user->hasRole(Utils::getSuperAdminName()) ? true : null);

        $this->admin = User::factory()->create([
            'is_active' => true,
            'default_establishment_id' => $this->matriz->id,
        ]);
        $this->admin->assignRole(Utils::getSuperAdminName());
        $this->actingAs($this->admin);

        $this->proveedor = Supplier::factory()->create(['is_generic' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function facturaPayload(array $overrides = []): array
    {
        return [
            'document_type' => SupplierDocumentType::Factura->value,
            'supplier_id' => $this->proveedor->id,
            'establishment_id' => $this->matriz->id,
            'supplier_invoice_number' => '001-001-01-00004567',
            'supplier_cai' => 'ABCDEF-123456-789ABC-DEF012-345678-AB',
            'date' => '2026-09-20',
            'payment_method' => PaymentMethod::Transferencia->value,
            ...$overrides,
        ];
    }

    // ─── Montos de una factura ───────────────────────────────

    public function test_factura_guarda_los_montos_y_el_servidor_deriva_subtotal_y_total(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm($this->facturaPayload([
                'exempt_total' => 250.00,
                'taxable_total' => 1000.00,
                'isv' => 150.00,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $purchase = Purchase::query()->latest('id')->firstOrFail();

        $this->assertEquals(1000.00, (float) $purchase->taxable_total);
        $this->assertEquals(250.00, (float) $purchase->exempt_total);
        $this->assertEquals(150.00, (float) $purchase->isv);
        $this->assertEquals(1250.00, (float) $purchase->subtotal);
        $this->assertEquals(1400.00, (float) $purchase->total);
        $this->assertSame(PurchaseStatus::Borrador, $purchase->status);
        $this->assertSame(0, $purchase->items()->count(), 'La compra ya no crea líneas de producto.');
    }

    public function test_el_isv_se_sugiere_al_ingresar_el_importe_gravado(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm(['taxable_total' => 1000.00])
            ->assertFormSet(['isv' => 150.00]);
    }

    public function test_isv_distinto_del_15_por_ciento_se_guarda_tal_cual_sin_bloquear(): void
    {
        // Proveedor que redondea línea por línea: 5 centavos de diferencia.
        Livewire::test(CreatePurchase::class)
            ->fillForm($this->facturaPayload([
                'exempt_total' => 0,
                'taxable_total' => 1000.00,
                'isv' => 150.05,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertEquals(150.05, (float) Purchase::query()->latest('id')->value('isv'),
            'Manda lo que dice el documento impreso.');
    }

    public function test_isv_sin_importe_gravado_se_rechaza_en_el_campo_isv(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm($this->facturaPayload([
                'exempt_total' => 500.00,
                'taxable_total' => 0,
                'isv' => 75.00,
            ]))
            ->call('create')
            ->assertHasFormErrors(['isv']);

        $this->assertSame(0, Purchase::count());
    }

    public function test_documento_en_cero_se_rechaza(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm($this->facturaPayload([
                'exempt_total' => 0,
                'taxable_total' => 0,
                'isv' => 0,
            ]))
            ->call('create')
            ->assertHasFormErrors(['taxable_total']);

        $this->assertSame(0, Purchase::count());
    }

    // ─── Unicidad del documento del proveedor ────────────────

    public function test_la_misma_factura_vigente_no_se_puede_registrar_dos_veces(): void
    {
        Purchase::factory()->fromSupplier($this->proveedor)->withTotals(taxable: 100)->create([
            'supplier_invoice_number' => '001-001-01-00004567',
            'status' => PurchaseStatus::Confirmada,
        ]);

        Livewire::test(CreatePurchase::class)
            ->fillForm($this->facturaPayload(['taxable_total' => 100.00, 'isv' => 15.00]))
            ->call('create')
            ->assertHasFormErrors(['supplier_invoice_number']);
    }

    public function test_una_factura_anulada_se_puede_volver_a_registrar(): void
    {
        Purchase::factory()->fromSupplier($this->proveedor)->withTotals(taxable: 100)->create([
            'supplier_invoice_number' => '001-001-01-00004567',
            'status' => PurchaseStatus::Anulada,
        ]);

        Livewire::test(CreatePurchase::class)
            ->fillForm($this->facturaPayload(['taxable_total' => 100.00, 'isv' => 15.00]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Purchase::where('supplier_invoice_number', '001-001-01-00004567')->count());
    }

    // ─── Editar Recibo Interno (regresión) ───────────────────

    public function test_editar_un_recibo_interno_conserva_el_proveedor_real(): void
    {
        $ri = Purchase::factory()->fromSupplier($this->proveedor)->create([
            'document_type' => SupplierDocumentType::ReciboInterno,
            'supplier_invoice_number' => 'RI-20260920-0001',
            'supplier_cai' => null,
            'date' => '2026-09-20',
            'status' => PurchaseStatus::Borrador,
            'exempt_total' => 300,
            'subtotal' => 300,
            'total' => 300,
        ]);

        Livewire::test(EditPurchase::class, ['record' => $ri->getRouteKey()])
            ->fillForm(['notes' => 'Corrección de nota'])
            ->call('save')
            ->assertHasNoFormErrors();

        $ri->refresh();
        $this->assertSame($this->proveedor->id, $ri->supplier_id,
            'Antes EditPurchase forzaba el genérico y borraba el proveedor real elegido.');
        $this->assertSame('RI-20260920-0001', $ri->supplier_invoice_number,
            'Misma fecha y mismo tipo: el correlativo RI no se regenera.');
        $this->assertSame('Corrección de nota', $ri->notes);
    }

    public function test_pasar_una_factura_a_recibo_interno_genera_correlativo_y_conserva_el_proveedor(): void
    {
        $hoy = CarbonImmutable::parse('2026-09-20');
        CarbonImmutable::setTestNow($hoy);
        \Carbon\Carbon::setTestNow($hoy);

        $factura = Purchase::factory()->fromSupplier($this->proveedor)->withTotals(taxable: 100)->create([
            'date' => $hoy->toDateString(),
            'status' => PurchaseStatus::Borrador,
        ]);

        Livewire::test(EditPurchase::class, ['record' => $factura->getRouteKey()])
            ->fillForm([
                'document_type' => SupplierDocumentType::ReciboInterno->value,
                'exempt_total' => 115.00,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $factura->refresh();
        $this->assertSame(SupplierDocumentType::ReciboInterno, $factura->document_type);
        $this->assertSame($this->proveedor->id, $factura->supplier_id);
        $this->assertSame('RI-20260920-0001', $factura->supplier_invoice_number);
        $this->assertNull($factura->supplier_cai);
        $this->assertEquals(0.0, (float) $factura->taxable_total, 'Al pasar a RI el gravado se limpia.');
        $this->assertEquals(0.0, (float) $factura->isv);
        $this->assertEquals(115.00, (float) $factura->total);

        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
    }

    // ─── Vista: confirmar y compras heredadas ────────────────

    public function test_confirmar_desde_la_vista_marca_la_compra_confirmada(): void
    {
        $purchase = Purchase::factory()->fromSupplier($this->proveedor)->withTotals(taxable: 1000)->create([
            'status' => PurchaseStatus::Borrador,
        ]);

        Livewire::test(ViewPurchase::class, ['record' => $purchase->getRouteKey()])
            ->callAction('confirm')
            ->assertHasNoActionErrors();

        $this->assertSame(PurchaseStatus::Confirmada, $purchase->fresh()->status);
    }

    public function test_borrador_heredado_con_productos_no_se_confirma_desde_la_vista(): void
    {
        $purchase = Purchase::factory()->fromSupplier($this->proveedor)->withTotals(taxable: 1000)->create([
            'status' => PurchaseStatus::Borrador,
        ]);
        PurchaseItem::factory()->forPurchase($purchase)->create();

        Livewire::test(ViewPurchase::class, ['record' => $purchase->getRouteKey()])
            ->callAction('confirm')
            ->assertNotified('No se pudo confirmar');

        $this->assertSame(PurchaseStatus::Borrador, $purchase->fresh()->status);
    }

    public function test_la_vista_muestra_los_productos_solo_en_compras_heredadas(): void
    {
        $product = Product::factory()->create();
        $heredada = Purchase::factory()->fromSupplier($this->proveedor)->confirmada()->withTotals(taxable: 1000)->create();
        PurchaseItem::factory()->forPurchase($heredada)->forProduct($product)->create();

        $actual = Purchase::factory()->fromSupplier($this->proveedor)->confirmada()->withTotals(taxable: 1000)->create();

        Livewire::test(ViewPurchase::class, ['record' => $heredada->getRouteKey()])
            ->assertSee('Productos (formato anterior)')
            ->assertSee($product->name);

        Livewire::test(ViewPurchase::class, ['record' => $actual->getRouteKey()])
            ->assertDontSee('Productos (formato anterior)');
    }
}

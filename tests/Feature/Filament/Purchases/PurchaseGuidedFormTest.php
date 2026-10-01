<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Purchases;

use App\Enums\ExpenseCategory;
use App\Enums\PurchaseKind;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Models\CashMovement;
use App\Models\Establishment;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Purchases\PurchaseService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Formulario guiado de Compras (rediseño 2026-09-29):
 *   - Elegir el tipo sugiere el documento (solo al crear).
 *   - "Guardar y confirmar" registra y confirma de un clic, sin mover la
 *     caja; si confirmar falla, queda como borrador y se avisa por qué.
 *   - Los montos empiezan vacíos: vacío cuenta como 0.
 *   - La sucursal se oculta con una sola sucursal activa, pero se guarda.
 *   - El resumen muestra el total y lo que pasará al confirmar.
 */
class PurchaseGuidedFormTest extends TestCase
{
    use CreatesMatriz, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Gate::before(fn ($user) => $user instanceof User && $user->hasRole(Utils::getSuperAdminName()) ? true : null);

        $this->admin = User::factory()->create([
            'is_active' => true,
            'default_establishment_id' => $this->matriz->id,
        ]);
        $this->admin->assignRole(Utils::getSuperAdminName());
        $this->actingAs($this->admin);

        CarbonImmutable::setTestNow('2026-09-29 10:00:00');
        \Carbon\Carbon::setTestNow('2026-09-29 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function taxiPayload(): array
    {
        return [
            'kind' => PurchaseKind::Gasto->value,
            'description' => 'Taxi a la SAR',
            'expense_category' => ExpenseCategory::Otros->value,
            'exempt_total' => 120.00,
        ];
    }

    // ─── Sugerencias al elegir el tipo ───────────────────────

    public function test_elegir_gasto_sugiere_recibo_sin_elegir_forma_de_pago(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm(['kind' => PurchaseKind::Gasto->value])
            ->assertFormSet([
                'document_type' => SupplierDocumentType::ReciboInterno->value,
                'payment_method' => null,
            ]);
    }

    public function test_volver_a_mercaderia_sugiere_factura(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm(['kind' => PurchaseKind::Gasto->value])
            ->fillForm(['kind' => PurchaseKind::Mercaderia->value])
            ->assertFormSet(['document_type' => SupplierDocumentType::Factura->value]);
    }

    public function test_al_editar_cambiar_el_tipo_no_cambia_el_documento(): void
    {
        $factura = Purchase::factory()->forEstablishment($this->matriz)->withTotals(taxable: 100)->create([
            'date' => '2026-09-29',
            'status' => PurchaseStatus::Borrador,
        ]);

        Livewire::test(EditPurchase::class, ['record' => $factura->getRouteKey()])
            ->fillForm(['kind' => PurchaseKind::Gasto->value])
            ->assertFormSet(['document_type' => SupplierDocumentType::Factura->value]);
    }

    // ─── Guardar y confirmar ─────────────────────────────────

    public function test_guardar_y_confirmar_registra_el_gasto_sin_mover_la_caja(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm($this->taxiPayload())
            ->call('createAndConfirm')
            ->assertHasNoFormErrors()
            ->assertNotified('Compra registrada y confirmada');

        $gasto = Purchase::query()->latest('id')->firstOrFail();
        $this->assertSame(PurchaseStatus::Confirmada, $gasto->status);
        $this->assertSame(SupplierDocumentType::ReciboInterno, $gasto->document_type);
        $this->assertNull($gasto->payment_method, 'La forma de pago es opcional.');
        $this->assertSame(0, CashMovement::count());
    }

    public function test_si_confirmar_falla_queda_el_borrador_y_avisa(): void
    {
        $this->app->instance(PurchaseService::class, new class extends PurchaseService
        {
            public function confirm(Purchase $purchase): void
            {
                throw new \InvalidArgumentException('El período fiscal ya fue declarado.');
            }
        });

        Livewire::test(CreatePurchase::class)
            ->fillForm($this->taxiPayload())
            ->call('createAndConfirm')
            ->assertHasNoFormErrors()
            ->assertNotified('Se guardó como borrador, sin confirmar');

        $this->assertSame(PurchaseStatus::Borrador, Purchase::query()->latest('id')->value('status'),
            'Lo capturado no se pierde.');
    }

    public function test_guardar_borrador_no_confirma(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm($this->taxiPayload())
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Borrador guardado');

        $this->assertSame(PurchaseStatus::Borrador, Purchase::query()->latest('id')->value('status'));
    }

    public function test_sin_permiso_de_confirmar_solo_se_guarda_borrador(): void
    {
        $role = Role::create(['name' => 'solo_crear', 'guard_name' => 'web']);
        foreach (['ViewAny', 'View', 'Create'] as $action) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => "{$action}:Purchase", 'guard_name' => 'web']));
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user);

        Livewire::test(CreatePurchase::class)
            ->assertDontSee('Guardar y confirmar')
            ->assertSee('Guardar borrador')
            // Aunque llame al método a mano, la compra no se confirma.
            ->fillForm($this->taxiPayload())
            ->call('createAndConfirm')
            ->assertHasNoFormErrors();

        $this->assertSame(PurchaseStatus::Borrador, Purchase::query()->latest('id')->value('status'));
    }

    // ─── Sucursal ────────────────────────────────────────────

    public function test_con_una_sola_sucursal_se_oculta_y_se_guarda_la_matriz(): void
    {
        Livewire::test(CreatePurchase::class)
            ->assertFormFieldHidden('establishment_id')
            ->fillForm($this->taxiPayload())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($this->matriz->id, Purchase::query()->latest('id')->value('establishment_id'));
    }

    public function test_con_varias_sucursales_se_puede_elegir(): void
    {
        Establishment::factory()->create(['is_main' => false, 'is_active' => true, 'code' => '002']);

        Livewire::test(CreatePurchase::class)
            ->assertFormFieldVisible('establishment_id');
    }

    // ─── Resumen ─────────────────────────────────────────────

    public function test_el_resumen_muestra_total_y_efectos_de_confirmar(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm($this->taxiPayload())
            ->assertSee('L 120.00')
            ->assertSee('No entra al Libro de Compras.')
            ->assertSee('Resta de la utilidad del mes.')
            ->assertDontSee('caja abierta');
    }

    public function test_los_montos_empiezan_vacios_y_vacio_cuenta_como_cero(): void
    {
        Livewire::test(CreatePurchase::class)
            ->assertFormSet(['exempt_total' => null, 'taxable_total' => null, 'isv' => null])
            ->fillForm([
                'supplier_id' => \App\Models\Supplier::factory()->create(['is_generic' => false])->id,
                'supplier_invoice_number' => '001-001-01-00000077',
                'supplier_cai' => 'ABCDEF-123456-789ABC-DEF012-345678-AB',
                'taxable_total' => 200.00,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $factura = Purchase::query()->latest('id')->firstOrFail();
        $this->assertEquals(0.00, (float) $factura->exempt_total, 'Exento vacío se guarda como 0.');
        $this->assertEquals(230.00, (float) $factura->total);
    }

    public function test_factura_sin_ningun_monto_se_rechaza(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'supplier_id' => \App\Models\Supplier::factory()->create(['is_generic' => false])->id,
                'supplier_invoice_number' => '001-001-01-00000078',
                'supplier_cai' => 'ABCDEF-123456-789ABC-DEF012-345678-AB',
            ])
            ->call('create')
            ->assertHasFormErrors(['taxable_total']);

        $this->assertSame(0, Purchase::count());
    }
}

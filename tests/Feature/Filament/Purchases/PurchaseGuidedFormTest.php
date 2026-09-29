<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Purchases;

use App\Enums\CashMovementType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseKind;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Models\CashMovement;
use App\Models\Establishment;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Cash\CashSessionService;
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
 *   - Elegir el tipo sugiere documento y forma de pago (solo al crear).
 *   - "Guardar y confirmar" registra y confirma de un clic; si confirmar
 *     falla, la compra queda como borrador y se avisa por qué.
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

    public function test_elegir_gasto_sugiere_recibo_y_efectivo(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm(['kind' => PurchaseKind::Gasto->value])
            ->assertFormSet([
                'document_type' => SupplierDocumentType::ReciboInterno->value,
                'payment_method' => PaymentMethod::Efectivo,
            ]);
    }

    public function test_volver_a_mercaderia_sugiere_factura_y_respeta_la_forma_de_pago(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm(['kind' => PurchaseKind::Gasto->value])
            ->fillForm(['kind' => PurchaseKind::Mercaderia->value])
            ->assertFormSet([
                'document_type' => SupplierDocumentType::Factura->value,
                'payment_method' => PaymentMethod::Efectivo,
            ]);
    }

    public function test_la_forma_de_pago_elegida_no_se_pisa(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm(['payment_method' => PaymentMethod::Transferencia->value])
            ->fillForm(['kind' => PurchaseKind::Gasto->value])
            ->assertFormSet(['payment_method' => PaymentMethod::Transferencia]);
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

    public function test_guardar_y_confirmar_registra_el_gasto_y_saca_el_efectivo(): void
    {
        $caja = app(CashSessionService::class)->open($this->matriz->id, $this->admin, 500.00);

        Livewire::test(CreatePurchase::class)
            ->fillForm($this->taxiPayload())
            ->call('createAndConfirm')
            ->assertHasNoFormErrors()
            ->assertNotified('Compra registrada y confirmada');

        $gasto = Purchase::query()->latest('id')->firstOrFail();
        $this->assertSame(PurchaseStatus::Confirmada, $gasto->status);
        $this->assertSame(SupplierDocumentType::ReciboInterno, $gasto->document_type);
        $this->assertDatabaseHas('cash_movements', [
            'cash_session_id' => $caja->id,
            'type' => CashMovementType::Expense->value,
            'amount' => '120.00',
            'reference_type' => Purchase::class,
            'reference_id' => $gasto->id,
        ]);
    }

    public function test_guardar_y_confirmar_sin_caja_abierta_deja_el_borrador_y_avisa(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm($this->taxiPayload())
            ->call('createAndConfirm')
            ->assertHasNoFormErrors()
            ->assertNotified('Se guardó como borrador, sin confirmar');

        $gasto = Purchase::query()->latest('id')->firstOrFail();
        $this->assertSame(PurchaseStatus::Borrador, $gasto->status, 'Lo capturado no se pierde.');
        $this->assertSame(0, CashMovement::count());
    }

    public function test_guardar_borrador_no_confirma(): void
    {
        app(CashSessionService::class)->open($this->matriz->id, $this->admin, 500.00);

        Livewire::test(CreatePurchase::class)
            ->fillForm($this->taxiPayload())
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Borrador guardado');

        $this->assertSame(PurchaseStatus::Borrador, Purchase::query()->latest('id')->value('status'));
        $this->assertSame(0, CashMovement::where('type', CashMovementType::Expense->value)->count());
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

        app(CashSessionService::class)->open($this->matriz->id, $this->admin, 500.00);

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
            ->assertSee('de la caja abierta')
            ->assertSee('Resta de la utilidad del mes.');
    }
}

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
use App\Filament\Resources\Purchases\Pages\ViewPurchase;
use App\Models\CashMovement;
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
 * Gastos registrados desde Compras ("todo en Compras", 2026-09-28):
 *   - Tipo Gasto exige categoría y concepto; Mercadería no los guarda.
 *   - El borrador no mueve la caja; confirmar un gasto en efectivo sí.
 *   - Permisos de las acciones: confirmar exige Update; anular una
 *     confirmada exige Delete (el cajero confirma sus gastos pero no anula).
 */
class PurchaseExpenseFormTest extends TestCase
{
    use CreatesMatriz, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Gate::before(fn ($user) => $user instanceof User && $user->hasRole(Utils::getSuperAdminName()) ? true : null);

        $this->admin = $this->makeUser();
        $this->admin->assignRole(Utils::getSuperAdminName());
        $this->actingAs($this->admin);

        CarbonImmutable::setTestNow('2026-09-28 10:00:00');
        \Carbon\Carbon::setTestNow('2026-09-28 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'default_establishment_id' => $this->matriz->id,
        ]);
    }

    /**
     * Usuario con un rol que solo tiene los permisos indicados de Compras.
     *
     * @param  list<string>  $actions  p. ej. ['View', 'Update']
     */
    private function userWithPurchasePermissions(array $actions): User
    {
        $role = Role::create(['name' => 'rol_'.implode('_', $actions), 'guard_name' => 'web']);

        foreach (['ViewAny', ...$actions] as $action) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => "{$action}:Purchase", 'guard_name' => 'web']));
        }

        $user = $this->makeUser();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * Taxi pagado del cajón: Recibo Interno, sin ISV.
     *
     * @return array<string, mixed>
     */
    private function gastoPayload(array $overrides = []): array
    {
        return [
            'kind' => PurchaseKind::Gasto->value,
            'expense_category' => ExpenseCategory::Otros->value,
            'description' => 'Taxi a la SAR',
            'payment_method' => PaymentMethod::Efectivo->value,
            'document_type' => SupplierDocumentType::ReciboInterno->value,
            'establishment_id' => $this->matriz->id,
            'date' => '2026-09-28',
            'exempt_total' => 120.00,
            ...$overrides,
        ];
    }

    // ─── Formulario ──────────────────────────────────────────

    public function test_gasto_exige_categoria_y_concepto(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm($this->gastoPayload(['expense_category' => null, 'description' => null]))
            ->call('create')
            ->assertHasFormErrors(['expense_category' => 'required', 'description' => 'required']);
    }

    public function test_crear_gasto_en_efectivo_guarda_el_borrador_sin_mover_la_caja(): void
    {
        Livewire::test(CreatePurchase::class)
            ->fillForm($this->gastoPayload())
            ->call('create')
            ->assertHasNoFormErrors();

        $gasto = Purchase::query()->latest('id')->firstOrFail();

        $this->assertSame(PurchaseKind::Gasto, $gasto->kind);
        $this->assertSame(ExpenseCategory::Otros, $gasto->expense_category);
        $this->assertSame('Taxi a la SAR', $gasto->description);
        $this->assertSame(PaymentMethod::Efectivo, $gasto->payment_method);
        $this->assertSame(PurchaseStatus::Borrador, $gasto->status);
        $this->assertSame('RI-20260928-0001', $gasto->supplier_invoice_number);
        $this->assertEquals(120.00, (float) $gasto->total);
        $this->assertSame(0, CashMovement::count(), 'El borrador todavía no saca dinero de la caja.');
    }

    public function test_pasar_un_gasto_a_mercaderia_limpia_categoria_y_concepto(): void
    {
        $gasto = Purchase::factory()->forEstablishment($this->matriz)->withTotals(taxable: 100)->create([
            'kind' => PurchaseKind::Gasto,
            'expense_category' => ExpenseCategory::Papeleria,
            'description' => 'Resmas de papel',
            'date' => '2026-09-28',
            'status' => PurchaseStatus::Borrador,
        ]);

        Livewire::test(EditPurchase::class, ['record' => $gasto->getRouteKey()])
            ->fillForm(['kind' => PurchaseKind::Mercaderia->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $gasto->refresh();
        $this->assertSame(PurchaseKind::Mercaderia, $gasto->kind);
        $this->assertNull($gasto->expense_category,
            'Una compra de mercadería no debe seguir contando como gasto de una categoría.');
        $this->assertNull($gasto->description);
    }

    public function test_confirmar_gasto_en_efectivo_desde_la_vista_saca_el_dinero_de_la_caja(): void
    {
        $caja = app(CashSessionService::class)->open($this->matriz->id, $this->admin, 500.00);

        Livewire::test(CreatePurchase::class)
            ->fillForm($this->gastoPayload())
            ->call('create');
        $gasto = Purchase::query()->latest('id')->firstOrFail();

        Livewire::test(ViewPurchase::class, ['record' => $gasto->getRouteKey()])
            ->callAction('confirm')
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('cash_movements', [
            'cash_session_id' => $caja->id,
            'type' => CashMovementType::Expense->value,
            'amount' => '120.00',
            'category' => ExpenseCategory::Otros->value,
            'reference_type' => Purchase::class,
            'reference_id' => $gasto->id,
        ]);
    }

    public function test_sin_caja_abierta_la_vista_avisa_y_el_gasto_queda_en_borrador(): void
    {
        $gasto = Purchase::factory()->forEstablishment($this->matriz)->create([
            'kind' => PurchaseKind::Gasto,
            'expense_category' => ExpenseCategory::Otros,
            'description' => 'Taxi',
            'payment_method' => PaymentMethod::Efectivo,
            'exempt_total' => 80,
            'subtotal' => 80,
            'total' => 80,
        ]);

        Livewire::test(ViewPurchase::class, ['record' => $gasto->getRouteKey()])
            ->callAction('confirm')
            ->assertNotified('No se pudo confirmar');

        $this->assertSame(PurchaseStatus::Borrador, $gasto->fresh()->status);
    }

    // ─── Permisos de las acciones ────────────────────────────

    public function test_con_update_se_confirma_y_se_descarta_el_borrador(): void
    {
        $borrador = Purchase::factory()->forEstablishment($this->matriz)->withTotals(taxable: 100)->create();
        $this->actingAs($this->userWithPurchasePermissions(['View', 'Update']));

        Livewire::test(ViewPurchase::class, ['record' => $borrador->getRouteKey()])
            ->assertActionVisible('confirm')
            ->assertActionVisible('cancel');
    }

    public function test_con_update_no_se_anula_una_compra_confirmada(): void
    {
        $confirmada = Purchase::factory()->forEstablishment($this->matriz)->withTotals(taxable: 100)->confirmada()->create();
        $this->actingAs($this->userWithPurchasePermissions(['View', 'Update']));

        Livewire::test(ViewPurchase::class, ['record' => $confirmada->getRouteKey()])
            ->assertActionHidden('cancel');
    }

    public function test_con_delete_se_anula_una_compra_confirmada(): void
    {
        $confirmada = Purchase::factory()->forEstablishment($this->matriz)->withTotals(taxable: 100)->confirmada()->create();
        $this->actingAs($this->userWithPurchasePermissions(['View', 'Update', 'Delete']));

        Livewire::test(ViewPurchase::class, ['record' => $confirmada->getRouteKey()])
            ->assertActionVisible('cancel');
    }

    public function test_solo_lectura_no_confirma_ni_anula(): void
    {
        $borrador = Purchase::factory()->forEstablishment($this->matriz)->withTotals(taxable: 100)->create();
        $this->actingAs($this->userWithPurchasePermissions(['View']));

        Livewire::test(ViewPurchase::class, ['record' => $borrador->getRouteKey()])
            ->assertActionHidden('confirm')
            ->assertActionHidden('cancel');
    }
}

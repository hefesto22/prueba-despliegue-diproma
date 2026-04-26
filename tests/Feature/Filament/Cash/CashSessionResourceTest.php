<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Cash;

use App\Enums\CashMovementType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Cash\Pages\ListCashSessions;
use App\Filament\Resources\Cash\RelationManagers\CashMovementsRelationManager;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Tests de integración de la UI Filament de Caja (C3).
 *
 * Cobertura crítica (Opción B del análisis de C3.6):
 *   1. La página ListCashSessions renderiza para un user con rol operativo.
 *   2. "Abrir caja" visible + submit crea sesión + asentamiento OpeningBalance.
 *   3. "Abrir caja" oculta cuando ya hay una sesión abierta en la sucursal.
 *   4. "Cerrar caja" con cuadre exacto deja discrepancy=0 sin autorizador.
 *   5. "Cerrar caja" con descuadre > tolerancia sin autorizador NO cierra.
 *   6. "Cerrar caja" con descuadre > tolerancia + autorizador válido cierra con
 *      authorized_by_user_id seteado.
 *   7. "Registrar gasto" crea CashMovement Expense + Efectivo + category.
 *   8. CashMovementsRelationManager es read-only (canCreate = false).
 *
 * La lógica de dominio pura (cálculos, locks, excepciones) ya está cubierta en
 * CashSessionServiceTest y CashBalanceCalculatorTest. Estos tests verifican la
 * orquestación UI → Service, no re-testean el dominio.
 *
 * Decisiones de autorización:
 *   - El user de las pruebas tiene rol `super_admin` — Shield registra un
 *     Gate::before que bypasea policies, lo que nos permite enfocar los tests
 *     en el flujo operativo sin configurar permisos finos por test.
 *   - Para el autorizador de cierre con descuadre, se crea un user separado con
 *     rol `admin` (consistente con authorizerOptions() en CloseCashSessionAction).
 */
class CashSessionResourceTest extends TestCase
{
    use RefreshDatabase, CreatesMatriz;

    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        // CreatesMatriz crea el CompanySetting y la matriz. Aseguramos una
        // tolerancia conocida para que los tests de descuadre sean determinísticos.
        $this->matrizCompany->update(['cash_discrepancy_tolerance' => 50.00]);
        Cache::put('company_settings', $this->matrizCompany->fresh(), 60 * 60 * 24);

        // Rol super_admin — Shield configuró intercept_gate='before', pero en
        // el contexto de Livewire::test() el hook del plugin no siempre se
        // registra a tiempo antes que Filament evalúe las policies. Replicamos
        // el bypass manualmente con Gate::before para que los tests se enfoquen
        // en el flujo operativo, no en la configuración fina de permisos.
        Role::firstOrCreate([
            'name' => Utils::getSuperAdminName(),
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Gate::before(function ($user) {
            if ($user instanceof User && $user->hasRole(Utils::getSuperAdminName())) {
                return true;
            }

            return null; // deja que continúen las policies por defecto
        });

        $this->cajero = User::factory()->create([
            'is_active' => true,
            'default_establishment_id' => $this->matriz->id,
        ]);
        $this->cajero->assignRole(Utils::getSuperAdminName());
    }

    // ─── Test 1: Render ──────────────────────────────────────

    public function test_list_cash_sessions_renderiza_sin_errores(): void
    {
        $this->actingAs($this->cajero);

        Livewire::test(ListCashSessions::class)
            ->assertSuccessful();
    }

    // ─── Test 2: Abrir caja (happy path) ─────────────────────

    public function test_accion_abrir_caja_visible_sin_sesion_y_submit_crea_sesion_y_opening_balance(): void
    {
        $this->actingAs($this->cajero);

        Livewire::test(ListCashSessions::class)
            ->assertActionVisible('openCashSession')
            ->callAction('openCashSession', data: [
                'opening_amount' => 1500.00,
            ])
            ->assertHasNoActionErrors();

        // Sesión creada con los campos esperados.
        $this->assertDatabaseHas('cash_sessions', [
            'establishment_id' => $this->matriz->id,
            'opened_by_user_id' => $this->cajero->id,
            'opening_amount' => '1500.00',
            'closed_at' => null,
        ]);

        $session = CashSession::where('establishment_id', $this->matriz->id)->firstOrFail();

        // Asentamiento OpeningBalance registrado por el service.
        $this->assertDatabaseHas('cash_movements', [
            'cash_session_id' => $session->id,
            'user_id' => $this->cajero->id,
            'type' => CashMovementType::OpeningBalance->value,
            'payment_method' => PaymentMethod::Efectivo->value,
            'amount' => '1500.00',
        ]);
    }

    // ─── Test 3: Abrir caja oculta si ya hay sesión ──────────

    public function test_accion_abrir_caja_oculta_cuando_ya_hay_sesion_abierta(): void
    {
        // Ya hay una sesión abierta en la matriz → la action "Abrir" se oculta.
        CashSession::factory()
            ->forEstablishment($this->matriz)
            ->openedBy($this->cajero)
            ->create();

        $this->actingAs($this->cajero);

        Livewire::test(ListCashSessions::class)
            ->assertActionHidden('openCashSession');
    }

    // ─── Test 4: Cerrar caja con cuadre exacto ───────────────

    public function test_accion_cerrar_caja_con_cuadre_exacto_sin_autorizador(): void
    {
        $session = CashSession::factory()
            ->forEstablishment($this->matriz)
            ->openedBy($this->cajero)
            ->openingAmount(1000.00)
            ->create();

        $this->actingAs($this->cajero);

        Livewire::test(ListCashSessions::class)
            ->callAction('closeCashSession', data: [
                'actual_amount' => 1000.00,
                'notes' => null,
                'authorized_by_user_id' => null,
            ])
            ->assertHasNoActionErrors();

        $fresh = $session->fresh();

        $this->assertNotNull($fresh->closed_at, 'La sesión debe quedar cerrada');
        $this->assertSame($this->cajero->id, $fresh->closed_by_user_id);
        $this->assertSame('1000.00', $fresh->expected_closing_amount);
        $this->assertSame('1000.00', $fresh->actual_closing_amount);
        $this->assertSame('0.00', $fresh->discrepancy);
        $this->assertNull($fresh->authorized_by_user_id);

        // Asentamiento ClosingBalance registrado por el service.
        $this->assertDatabaseHas('cash_movements', [
            'cash_session_id' => $session->id,
            'type' => CashMovementType::ClosingBalance->value,
            'amount' => '1000.00',
        ]);
    }

    // ─── Test 5: Cerrar con descuadre sin autorizador ────────

    public function test_accion_cerrar_caja_descuadre_sobre_tolerancia_sin_autorizador_no_cierra(): void
    {
        $session = CashSession::factory()
            ->forEstablishment($this->matriz)
            ->openedBy($this->cajero)
            ->openingAmount(1000.00)
            ->create();

        $this->actingAs($this->cajero);

        // actual=700, expected=1000, descuadre=-300, tolerancia=50 → supera.
        // Sin autorizador, la UI exige el Select (required reactivo) y/o
        // el service lanza DescuadreExcedeTolerancianException. Ambos caminos
        // convergen al invariante operativo: la sesión NO se cierra.
        Livewire::test(ListCashSessions::class)
            ->callAction('closeCashSession', data: [
                'actual_amount' => 700.00,
                'notes' => 'Posible robo',
                'authorized_by_user_id' => null,
            ]);

        $fresh = $session->fresh();

        $this->assertNull(
            $fresh->closed_at,
            'Sesión con descuadre > tolerancia sin autorizador NO debe cerrarse',
        );
        $this->assertNull($fresh->closed_by_user_id);
        $this->assertNull($fresh->actual_closing_amount);
    }

    // ─── Test 6: Cerrar con descuadre + autorizador ──────────

    public function test_accion_cerrar_caja_descuadre_sobre_tolerancia_con_autorizador_cierra(): void
    {
        $session = CashSession::factory()
            ->forEstablishment($this->matriz)
            ->openedBy($this->cajero)
            ->openingAmount(1000.00)
            ->create();

        // Autorizador con rol admin — elegible según authorizerOptions().
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $gerente = User::factory()->create(['is_active' => true]);
        $gerente->assignRole($adminRole);

        $this->actingAs($this->cajero);

        Livewire::test(ListCashSessions::class)
            ->callAction('closeCashSession', data: [
                'actual_amount' => 700.00,
                'notes' => 'Robo reportado — ver incidente #42',
                'authorized_by_user_id' => $gerente->id,
            ])
            ->assertHasNoActionErrors();

        $fresh = $session->fresh();

        $this->assertNotNull($fresh->closed_at, 'La sesión debe quedar cerrada con autorizador válido');
        $this->assertSame('-300.00', $fresh->discrepancy);
        $this->assertSame($gerente->id, $fresh->authorized_by_user_id);
        $this->assertSame('Robo reportado — ver incidente #42', $fresh->notes);
    }

    // ─── Test 7: Registrar gasto ─────────────────────────────

    public function test_accion_registrar_gasto_crea_cash_movement_expense(): void
    {
        $session = CashSession::factory()
            ->forEstablishment($this->matriz)
            ->openedBy($this->cajero)
            ->openingAmount(1000.00)
            ->create();

        $this->actingAs($this->cajero);

        // Schema actual del RecordExpenseAction (ver docblock allí — el action
        // evolucionó para soportar payment_method seleccionable y campos
        // fiscales opcionales). Los nombres de campo cambiaron respecto a la
        // versión anterior:
        //   - 'amount' → 'amount_total'
        //   - 'occurred_at' → 'expense_date' (formato Y-m-d, no datetime)
        //   - 'payment_method' es ahora required (default Efectivo)
        Livewire::test(ListCashSessions::class)
            ->callAction('recordExpense', data: [
                'amount_total' => 75.00,
                'payment_method' => PaymentMethod::Efectivo->value,
                'category' => ExpenseCategory::Combustible->value,
                'description' => 'Gasolina moto mensajero recibo #1234',
                'expense_date' => now()->format('Y-m-d'),
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('cash_movements', [
            'cash_session_id' => $session->id,
            'user_id' => $this->cajero->id,
            'type' => CashMovementType::Expense->value,
            'payment_method' => PaymentMethod::Efectivo->value,
            'amount' => '75.00',
            'category' => ExpenseCategory::Combustible->value,
            'description' => 'Gasolina moto mensajero recibo #1234',
        ]);

        // Defensa: no se crean movimientos espurios en otra sesión.
        $this->assertSame(1, CashMovement::where('cash_session_id', $session->id)->count());
    }

    // ─── Test 8: RelationManager read-only ───────────────────

    public function test_cash_movements_relation_manager_es_read_only(): void
    {
        // canCreate() false por contrato: los movimientos solo se crean vía
        // CashSessionService / SaleService / RecordExpenseAction. Nunca desde
        // el Filament UI del RelationManager.
        $rm = new CashMovementsRelationManager();

        $this->assertFalse(
            $rm->canCreate(),
            'CashMovementsRelationManager NUNCA debe permitir crear movimientos desde Filament UI',
        );

        // Contrato estructural: la relación apunta a `movements`. Como la
        // propiedad es protected static (definida por Filament), se accede
        // vía reflection — equivale a verificar el contrato sin acoplarse al
        // modificador de visibilidad.
        $reflection = new \ReflectionClass(CashMovementsRelationManager::class);
        $this->assertSame(
            'movements',
            $reflection->getProperty('relationship')->getValue(),
        );
    }
}

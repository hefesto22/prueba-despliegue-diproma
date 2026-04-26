<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Cash;

use App\Enums\CashMovementType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Exceptions\Cash\CajaYaAbiertaException;
use App\Exceptions\Cash\ConciliacionPendienteException;
use App\Exceptions\Cash\DescuadreExcedeTolerancianException;
use App\Exceptions\Cash\MovimientoEnSesionCerradaException;
use App\Exceptions\Cash\NoHayCajaAbiertaException;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cubre el ciclo de vida de una sesión de caja.
 *
 * Escenarios:
 *   - Apertura: exitosa, bloqueada por sesión ya abierta, con asentamiento
 *     opening_balance automático.
 *   - Cierre: exacto (discrepancy = 0), con descuadre tolerado, con descuadre
 *     que exige autorización, con autorización provista, sobre sesión ya cerrada.
 *   - Lookups: currentOpenSession (null si no hay), OrFail (lanza NoHayCaja...).
 *   - recordMovement: crea en sesión abierta, lanza NoHayCaja... si no hay.
 *   - Aislamiento entre sucursales: dos matrices pueden tener caja abierta
 *     simultáneamente sin interferir.
 */
class CashSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashSessionService $service;

    private CompanySetting $company;

    private Establishment $matriz;

    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');
        // RefreshDatabase trunca la tabla users entre tests pero la cache estática
        // de User::system() sobrevive en el proceso PHP — sin esto, el segundo
        // test que llame closeBySystem() recibe un User con id de un registro ya
        // borrado y FAILS con FK violation al insertar el cash_movement.
        User::clearSystemUserCache();

        $this->company = CompanySetting::factory()->create([
            'rtn' => '08011999123456',
            'cash_discrepancy_tolerance' => 50.00,
        ]);
        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        $this->cajero = User::factory()->create();
        $this->service = app(CashSessionService::class);
    }

    private function refreshCompanyCache(): void
    {
        Cache::put('company_settings', $this->company->fresh(), 60 * 60 * 24);
    }

    // ─── Apertura ─────────────────────────────────────────────

    public function test_open_crea_sesion_abierta_con_asentamiento_opening_balance(): void
    {
        $session = $this->service->open($this->matriz->id, $this->cajero, 1500.00);

        $this->assertTrue($session->isOpen());
        $this->assertSame($this->matriz->id, $session->establishment_id);
        $this->assertSame($this->cajero->id, $session->opened_by_user_id);
        $this->assertSame('1500.00', $session->opening_amount);

        // Debe existir un movimiento opening_balance con el mismo monto.
        $opening = $session->movements()->where('type', CashMovementType::OpeningBalance->value)->first();
        $this->assertNotNull($opening);
        $this->assertSame('1500.00', $opening->amount);
        $this->assertSame(PaymentMethod::Efectivo, $opening->payment_method);
    }

    public function test_open_falla_cuando_ya_hay_sesion_abierta_en_la_sucursal(): void
    {
        $this->service->open($this->matriz->id, $this->cajero, 1000.00);

        $this->expectException(CajaYaAbiertaException::class);
        $this->service->open($this->matriz->id, $this->cajero, 500.00);
    }

    public function test_open_permite_abrir_en_sucursal_distinta_aunque_la_otra_este_abierta(): void
    {
        $sucursalB = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create(['is_main' => false]);

        $this->service->open($this->matriz->id, $this->cajero, 1000.00);
        $sessionB = $this->service->open($sucursalB->id, $this->cajero, 500.00);

        $this->assertTrue($sessionB->isOpen());
        $this->assertSame($sucursalB->id, $sessionB->establishment_id);
    }

    // ─── Cierre: cuadre exacto ────────────────────────────────

    public function test_close_con_cuadre_exacto_deja_discrepancy_en_cero(): void
    {
        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);

        CashMovement::factory()->forSession($session)->saleIncome(500.00)->create();

        $closed = $this->service->close(
            session: $session->fresh(),
            closedBy: $this->cajero,
            actualClosingAmount: 1500.00,
        );

        $this->assertTrue($closed->isClosed());
        $this->assertSame('1500.00', $closed->expected_closing_amount);
        $this->assertSame('1500.00', $closed->actual_closing_amount);
        $this->assertSame('0.00', $closed->discrepancy);
        $this->assertNull($closed->authorized_by_user_id);

        // Asentamiento closing_balance automático.
        $closingMovement = $closed->movements()
            ->where('type', CashMovementType::ClosingBalance->value)
            ->first();
        $this->assertNotNull($closingMovement);
        $this->assertSame('1500.00', $closingMovement->amount);
    }

    // ─── Cierre: dentro de tolerancia ─────────────────────────

    public function test_close_permite_descuadre_dentro_de_tolerancia_sin_autorizador(): void
    {
        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);

        // Falta L. 30, tolerancia L. 50 → permitido sin autorización.
        $closed = $this->service->close(
            session: $session->fresh(),
            closedBy: $this->cajero,
            actualClosingAmount: 970.00,
            notes: 'Posible error al dar cambio',
        );

        $this->assertSame('-30.00', $closed->discrepancy);
        $this->assertNull($closed->authorized_by_user_id);
    }

    // ─── Cierre: fuera de tolerancia ──────────────────────────

    public function test_close_lanza_excepcion_cuando_descuadre_supera_tolerancia_sin_autorizador(): void
    {
        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);

        // Falta L. 200, tolerancia L. 50 → requiere autorización.
        $this->expectException(DescuadreExcedeTolerancianException::class);
        $this->service->close(
            session: $session->fresh(),
            closedBy: $this->cajero,
            actualClosingAmount: 800.00,
        );
    }

    public function test_close_permite_descuadre_grande_si_hay_autorizador(): void
    {
        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);
        $gerente = User::factory()->create();

        $closed = $this->service->close(
            session: $session->fresh(),
            closedBy: $this->cajero,
            actualClosingAmount: 800.00,
            notes: 'Robo reportado — ver incidente #42',
            authorizedBy: $gerente,
        );

        $this->assertSame('-200.00', $closed->discrepancy);
        $this->assertSame($gerente->id, $closed->authorized_by_user_id);
        $this->assertSame('Robo reportado — ver incidente #42', $closed->notes);
    }

    public function test_close_respeta_tolerancia_configurada_en_company_settings(): void
    {
        // Subir la tolerancia a L. 500 → un descuadre de L. 400 debe pasar.
        $this->company->update(['cash_discrepancy_tolerance' => 500.00]);
        $this->refreshCompanyCache();

        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);

        $closed = $this->service->close(
            session: $session->fresh(),
            closedBy: $this->cajero,
            actualClosingAmount: 600.00,
        );

        $this->assertSame('-400.00', $closed->discrepancy);
        $this->assertNull($closed->authorized_by_user_id);
    }

    // ─── Cierre: sesión ya cerrada ────────────────────────────

    public function test_close_falla_sobre_sesion_ya_cerrada(): void
    {
        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);
        $closed = $this->service->close($session->fresh(), $this->cajero, 1000.00);

        $this->expectException(MovimientoEnSesionCerradaException::class);
        $this->service->close($closed, $this->cajero, 1000.00);
    }

    // ─── Lookups ──────────────────────────────────────────────

    public function test_current_open_session_retorna_null_cuando_no_hay_ninguna(): void
    {
        $this->assertNull($this->service->currentOpenSession($this->matriz->id));
    }

    public function test_current_open_session_retorna_la_unica_abierta(): void
    {
        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);

        $found = $this->service->currentOpenSession($this->matriz->id);
        $this->assertNotNull($found);
        $this->assertSame($session->id, $found->id);
    }

    public function test_current_open_session_or_fail_lanza_excepcion_tipada(): void
    {
        $this->expectException(NoHayCajaAbiertaException::class);
        $this->service->currentOpenSessionOrFail($this->matriz->id);
    }

    // ─── recordMovement ───────────────────────────────────────

    public function test_record_movement_crea_el_movimiento_en_la_sesion_abierta(): void
    {
        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);

        $movement = $this->service->recordMovement($this->matriz->id, [
            'user_id' => $this->cajero->id,
            'type' => CashMovementType::Expense,
            'payment_method' => PaymentMethod::Efectivo,
            'amount' => 75.00,
            'category' => ExpenseCategory::Combustible,
            'description' => 'Gasolina de mensajería',
        ]);

        $this->assertSame($session->id, $movement->cash_session_id);
        $this->assertSame('75.00', $movement->amount);
        $this->assertSame(CashMovementType::Expense, $movement->type);
    }

    public function test_record_movement_lanza_excepcion_cuando_no_hay_caja_abierta(): void
    {
        $this->expectException(NoHayCajaAbiertaException::class);
        $this->service->recordMovement($this->matriz->id, [
            'user_id' => $this->cajero->id,
            'type' => CashMovementType::Expense,
            'payment_method' => PaymentMethod::Efectivo,
            'amount' => 50.00,
        ]);
    }

    // ─── recordMovementWithinTransaction ──────────────────────
    //
    // E.2.A2 — variante que NO abre transacción propia. El caller debe estar
    // ya dentro de una `DB::transaction(...)` para que el lockForUpdate tenga
    // semántica y para que los INSERT se reviertan si el caller aborta.

    public function test_record_movement_within_transaction_crea_movimiento_bajo_la_transaccion_del_caller(): void
    {
        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);

        $movement = DB::transaction(fn () => $this->service->recordMovementWithinTransaction(
            $this->matriz->id,
            [
                'user_id' => $this->cajero->id,
                'type' => CashMovementType::Expense,
                'payment_method' => PaymentMethod::Efectivo,
                'amount' => 40.00,
                'category' => ExpenseCategory::Combustible,
                'description' => 'Dentro de transacción del caller',
            ],
        ));

        $this->assertSame($session->id, $movement->cash_session_id);
        $this->assertSame('40.00', $movement->amount);

        // Persistió tras el commit externo.
        $this->assertDatabaseHas('cash_movements', [
            'id' => $movement->id,
            'amount' => 40.00,
        ]);
    }

    public function test_record_movement_within_transaction_se_revierte_si_el_caller_aborta(): void
    {
        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);

        try {
            DB::transaction(function () use ($session) {
                $this->service->recordMovementWithinTransaction($this->matriz->id, [
                    'user_id' => $this->cajero->id,
                    'type' => CashMovementType::Expense,
                    'payment_method' => PaymentMethod::Efectivo,
                    'amount' => 99.00,
                    'description' => 'Se debe revertir',
                ]);

                // El caller aborta: el INSERT debe revertirse porque no hubo
                // savepoint intermedio. Esa es la razón de existir de este método.
                throw new \RuntimeException('Abort intencional del caller');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertDatabaseMissing('cash_movements', [
            'cash_session_id' => $session->id,
            'amount' => 99.00,
        ]);
    }

    public function test_record_movement_within_transaction_lanza_excepcion_cuando_no_hay_caja_abierta(): void
    {
        $this->expectException(NoHayCajaAbiertaException::class);

        DB::transaction(fn () => $this->service->recordMovementWithinTransaction($this->matriz->id, [
            'user_id' => $this->cajero->id,
            'type' => CashMovementType::Expense,
            'payment_method' => PaymentMethod::Efectivo,
            'amount' => 10.00,
        ]));
    }

    // ─── Aislamiento por sucursal ─────────────────────────────

    public function test_sesiones_de_distintas_sucursales_no_interfieren(): void
    {
        $sucursalB = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create(['is_main' => false]);

        $sessionA = $this->service->open($this->matriz->id, $this->cajero, 1000.00);
        $sessionB = $this->service->open($sucursalB->id, $this->cajero, 500.00);

        CashMovement::factory()->forSession($sessionA)->saleIncome(300.00)->create();
        CashMovement::factory()->forSession($sessionB)->saleIncome(200.00)->create();

        // Cierro solo la A — la B debe seguir abierta e intacta.
        $closedA = $this->service->close($sessionA->fresh(), $this->cajero, 1300.00);

        $this->assertTrue($closedA->isClosed());
        $this->assertTrue($sessionB->fresh()->isOpen());

        $this->assertSame($sessionB->id, $this->service->currentOpenSession($sucursalB->id)->id);
        $this->assertNull($this->service->currentOpenSession($this->matriz->id));
    }

    public function test_close_recalcula_expected_a_partir_de_movimientos_actuales(): void
    {
        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);

        CashMovement::factory()->forSession($session)->saleIncome(500.00, PaymentMethod::Efectivo)->create();
        CashMovement::factory()->forSession($session)->saleIncome(300.00, PaymentMethod::TarjetaCredito)->create(); // no afecta
        CashMovement::factory()->forSession($session)->expense(100.00)->create();

        // Expected = 1000 + 500 - 100 = 1400
        $closed = $this->service->close($session->fresh(), $this->cajero, 1400.00);

        $this->assertSame('1400.00', $closed->expected_closing_amount);
        $this->assertSame('0.00', $closed->discrepancy);
    }

    // ─── closeBySystem (auto-cierre) ──────────────────────────
    //
    // Reglas críticas que NO comparte con close():
    //   - No recibe actual_closing_amount (sistema no contó plata).
    //   - Marca closed_by_system_at + requires_reconciliation=true.
    //   - SÍ calcula y guarda expected_closing_amount como referencia futura.
    //   - Crea CashMovement closing_balance atribuido al user "sistema".
    //   - actual_closing_amount/discrepancy/closed_by_user_id quedan NULL hasta
    //     que reconcile() los complete con el conteo físico real posterior.

    public function test_close_by_system_marca_closed_at_y_requires_reconciliation_y_calcula_expected(): void
    {
        $this->seed(\Database\Seeders\SystemUserSeeder::class);

        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);
        CashMovement::factory()->forSession($session)->saleIncome(500.00, PaymentMethod::Efectivo)->create();
        CashMovement::factory()->forSession($session)->expense(100.00)->create();

        $closed = $this->service->closeBySystem($session->fresh());

        $this->assertTrue($closed->isClosed());
        $this->assertNotNull($closed->closed_by_system_at);
        $this->assertTrue($closed->isPendingReconciliation());
        $this->assertTrue($closed->wasClosedBySystem());

        // Expected calculado: 1000 + 500 - 100 = 1400
        $this->assertSame('1400.00', $closed->expected_closing_amount);

        // Campos pendientes de conciliación posterior — quedan NULL.
        $this->assertNull($closed->actual_closing_amount);
        $this->assertNull($closed->discrepancy);
        $this->assertNull($closed->closed_by_user_id);
        $this->assertNull($closed->authorized_by_user_id);
    }

    public function test_close_by_system_crea_asentamiento_atribuido_al_user_sistema(): void
    {
        $this->seed(\Database\Seeders\SystemUserSeeder::class);

        $session = $this->service->open($this->matriz->id, $this->cajero, 800.00);
        CashMovement::factory()->forSession($session)->saleIncome(200.00, PaymentMethod::Efectivo)->create();

        $closed = $this->service->closeBySystem($session->fresh());

        $closingMovement = $closed->movements()
            ->where('type', CashMovementType::ClosingBalance->value)
            ->first();

        $this->assertNotNull($closingMovement);
        $this->assertSame('1000.00', $closingMovement->amount); // = expected (sin conteo físico)
        $this->assertSame('Cierre automático del sistema', $closingMovement->description);

        // Atribución: NO al cajero que abrió, sí al system user.
        $systemUser = User::system();
        $this->assertSame($systemUser->id, $closingMovement->user_id);
        $this->assertNotSame($this->cajero->id, $closingMovement->user_id);
    }

    public function test_close_by_system_falla_si_la_sesion_ya_esta_cerrada(): void
    {
        $this->seed(\Database\Seeders\SystemUserSeeder::class);

        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);
        $this->service->close($session->fresh(), $this->cajero, 1000.00);

        // Sesión ya cerrada por humano: el job debe abortar limpio sobre esta
        // sesión para que el caller capture y siga con la siguiente.
        $this->expectException(MovimientoEnSesionCerradaException::class);
        $this->service->closeBySystem($session->fresh());
    }

    // ─── reconcile (conciliación posterior al auto-cierre) ────
    //
    // La conciliación COMPLETA una sesión auto-cerrada con el conteo físico
    // real que el cajero/admin hace al día siguiente. Aplica las mismas reglas
    // de tolerancia que close() pero solo sobre sesiones pendientes.

    public function test_reconcile_completa_sesion_auto_cerrada_con_cuadre_exacto(): void
    {
        $this->seed(\Database\Seeders\SystemUserSeeder::class);

        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);
        CashMovement::factory()->forSession($session)->saleIncome(500.00, PaymentMethod::Efectivo)->create();
        $autoClosed = $this->service->closeBySystem($session->fresh());

        // Expected = 1500. Al día siguiente el cajero cuenta y hay exactamente 1500.
        $admin = User::factory()->create();
        $reconciled = $this->service->reconcile(
            session: $autoClosed->fresh(),
            reconciledBy: $admin,
            actualClosingAmount: 1500.00,
            notes: 'Conciliación con conteo del día siguiente',
        );

        $this->assertFalse($reconciled->isPendingReconciliation());
        $this->assertSame($admin->id, $reconciled->closed_by_user_id);
        $this->assertSame('1500.00', $reconciled->expected_closing_amount);
        $this->assertSame('1500.00', $reconciled->actual_closing_amount);
        $this->assertSame('0.00', $reconciled->discrepancy);
        $this->assertSame('Conciliación con conteo del día siguiente', $reconciled->notes);
    }

    public function test_reconcile_falla_si_la_sesion_no_esta_pendiente_de_conciliacion(): void
    {
        // Sesión cerrada manualmente (no requires_reconciliation): no hay nada
        // que conciliar — el flujo correcto era close(), reconcile no aplica.
        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);
        $closed = $this->service->close($session->fresh(), $this->cajero, 1000.00);

        $admin = User::factory()->create();

        $this->expectException(MovimientoEnSesionCerradaException::class);
        $this->service->reconcile(
            session: $closed,
            reconciledBy: $admin,
            actualClosingAmount: 1000.00,
        );
    }

    public function test_reconcile_descuadre_sobre_tolerancia_sin_autorizador_lanza_excepcion(): void
    {
        $this->seed(\Database\Seeders\SystemUserSeeder::class);

        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);
        CashMovement::factory()->forSession($session)->saleIncome(500.00, PaymentMethod::Efectivo)->create();
        $autoClosed = $this->service->closeBySystem($session->fresh());

        // Expected 1500 pero solo aparecen 1000 → descuadre L. -500, tolerancia L. 50.
        $admin = User::factory()->create();

        $this->expectException(DescuadreExcedeTolerancianException::class);
        $this->service->reconcile(
            session: $autoClosed->fresh(),
            reconciledBy: $admin,
            actualClosingAmount: 1000.00,
        );

        // Defensa: asegurar que el flag de pendiente no se borró ante el throw.
        $this->assertTrue($autoClosed->fresh()->isPendingReconciliation());
    }

    public function test_reconcile_con_autorizador_permite_descuadre_grande(): void
    {
        $this->seed(\Database\Seeders\SystemUserSeeder::class);

        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);
        CashMovement::factory()->forSession($session)->saleIncome(500.00, PaymentMethod::Efectivo)->create();
        $autoClosed = $this->service->closeBySystem($session->fresh());

        $admin   = User::factory()->create();
        $gerente = User::factory()->create();

        $reconciled = $this->service->reconcile(
            session: $autoClosed->fresh(),
            reconciledBy: $admin,
            actualClosingAmount: 1000.00,
            notes: 'Faltante atribuido a robo nocturno — incidente #87',
            authorizedBy: $gerente,
        );

        $this->assertSame('-500.00', $reconciled->discrepancy);
        $this->assertSame($gerente->id, $reconciled->authorized_by_user_id);
        $this->assertSame($admin->id, $reconciled->closed_by_user_id);
        $this->assertFalse($reconciled->isPendingReconciliation());
    }

    // ─── Grace period: bloqueo en open() ──────────────────────
    //
    // Si una sesión auto-cerrada queda > N días sin conciliar, el sistema
    // bloquea nuevas aperturas en esa sucursal hasta que un humano resuelva
    // la conciliación. Esto evita acumular cierres fantasma sin conteo real.

    public function test_open_falla_si_hay_sesion_pendiente_excedida_grace_period(): void
    {
        $this->seed(\Database\Seeders\SystemUserSeeder::class);

        // Default 7 días — simulamos auto-cierre hace 10 días.
        config(['cash.reconciliation_grace_days' => 7]);

        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);
        $this->service->closeBySystem($session->fresh());

        // Forzamos closed_by_system_at hacia atrás 10 días.
        CashSession::query()
            ->whereKey($session->id)
            ->update(['closed_by_system_at' => now()->subDays(10)]);

        $this->expectException(ConciliacionPendienteException::class);
        $this->service->open($this->matriz->id, $this->cajero, 500.00);
    }

    public function test_open_permite_abrir_si_sesion_pendiente_dentro_grace_period(): void
    {
        $this->seed(\Database\Seeders\SystemUserSeeder::class);

        config(['cash.reconciliation_grace_days' => 7]);

        $session = $this->service->open($this->matriz->id, $this->cajero, 1000.00);
        $this->service->closeBySystem($session->fresh());

        // 3 días < 7: dentro del grace period, no debe bloquear.
        CashSession::query()
            ->whereKey($session->id)
            ->update(['closed_by_system_at' => now()->subDays(3)]);

        $newSession = $this->service->open($this->matriz->id, $this->cajero, 500.00);

        $this->assertTrue($newSession->isOpen());
        $this->assertNotSame($session->id, $newSession->id);
    }
}

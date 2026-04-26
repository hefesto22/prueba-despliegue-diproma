<?php

namespace Tests\Feature\Services\FiscalPeriods;

use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\FiscalPeriod;
use App\Models\IsvMonthlyDeclaration;
use App\Models\IsvRetentionReceived;
use App\Models\User;
use App\Services\FiscalPeriods\Exceptions\DeclaracionIsvYaExisteException;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalNoReabiertoException;
use App\Services\FiscalPeriods\Exceptions\SnapshotActivoNoExisteException;
use App\Services\FiscalPeriods\FiscalPeriodService;
use App\Services\FiscalPeriods\IsvMonthlyDeclarationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tests de integración de IsvMonthlyDeclarationService (ISV.4).
 *
 * Cubre:
 *   1. computeTotalsFor() — cuadratura con libros SAR + retenciones + saldo previo.
 *   2. declare() — happy path + precondiciones (snapshot ya existe).
 *   3. redeclare() — happy path rectificativa + precondiciones (no reabierto,
 *      sin snapshot activo previo).
 *   4. Atomicidad — rollback si alguna fase falla.
 *   5. Cadena de saldo arrastrado entre meses consecutivos.
 *
 * Estrategia: factory de snapshots para los casos de precondición; para el
 * happy path de cómputo se crean datos mínimos en libros (cero retenciones,
 * cero ventas) y se verifica que la aritmética fluye. La cuadratura fina de
 * ventas/compras ya está cubierta por SalesBookServiceTest y PurchaseBookServiceTest.
 */
class IsvMonthlyDeclarationServiceTest extends TestCase
{
    use RefreshDatabase;

    private IsvMonthlyDeclarationService $service;

    private FiscalPeriodService $fiscalPeriodService;

    private CompanySetting $company;

    private Establishment $matriz;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');

        $this->company = CompanySetting::factory()->create([
            'fiscal_period_start' => '2026-01-01',
        ]);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        $this->refreshCompanyCache();

        $this->service = app(IsvMonthlyDeclarationService::class);
        $this->fiscalPeriodService = app(FiscalPeriodService::class);
    }

    private function refreshCompanyCache(): void
    {
        Cache::put('company_settings', $this->company->fresh(), 60 * 60 * 24);
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    // ═══════════════════════════════════════════════════════
    // 1. computeTotalsFor() — cálculo puro (QUERY)
    // ═══════════════════════════════════════════════════════

    public function test_compute_totals_for_mes_sin_actividad_retorna_todo_cero(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->create();

        $totals = $this->service->computeTotalsFor($period);

        $this->assertEquals(0.00, $totals->ventasGravadas);
        $this->assertEquals(0.00, $totals->ventasExentas);
        $this->assertEquals(0.00, $totals->ventasTotales);
        $this->assertEquals(0.00, $totals->comprasGravadas);
        $this->assertEquals(0.00, $totals->comprasExentas);
        $this->assertEquals(0.00, $totals->comprasTotales);
        $this->assertEquals(0.00, $totals->isvDebitoFiscal);
        $this->assertEquals(0.00, $totals->isvCreditoFiscal);
        $this->assertEquals(0.00, $totals->isvRetencionesRecibidas);
        $this->assertEquals(0.00, $totals->saldoAFavorAnterior);
        $this->assertEquals(0.00, $totals->isvAPagar);
        $this->assertEquals(0.00, $totals->saldoAFavorSiguiente);
    }

    public function test_compute_totals_for_suma_retenciones_del_periodo(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->create();

        IsvRetentionReceived::factory()->create([
            'period_year' => 2026,
            'period_month' => 2,
            'amount' => 150.00,
        ]);
        IsvRetentionReceived::factory()->create([
            'period_year' => 2026,
            'period_month' => 2,
            'amount' => 75.50,
        ]);
        // Retención de OTRO mes — no debe sumarse.
        IsvRetentionReceived::factory()->create([
            'period_year' => 2026,
            'period_month' => 3,
            'amount' => 999.00,
        ]);

        $totals = $this->service->computeTotalsFor($period);

        $this->assertEquals(225.50, $totals->isvRetencionesRecibidas);
    }

    public function test_compute_totals_for_arrastra_saldo_a_favor_del_mes_previo(): void
    {
        // Mes previo con snapshot activo que cerró con saldo a favor.
        $prevPeriod = FiscalPeriod::factory()->forMonth(2026, 1)->create();
        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($prevPeriod)
            ->create(['saldo_a_favor_siguiente' => 1_234.56]);

        $currentPeriod = FiscalPeriod::factory()->forMonth(2026, 2)->create();

        $totals = $this->service->computeTotalsFor($currentPeriod);

        $this->assertEquals(1_234.56, $totals->saldoAFavorAnterior);
    }

    public function test_compute_totals_for_no_arrastra_si_mes_previo_no_existe(): void
    {
        // Sin FiscalPeriod para enero.
        $currentPeriod = FiscalPeriod::factory()->forMonth(2026, 2)->create();

        $totals = $this->service->computeTotalsFor($currentPeriod);

        $this->assertEquals(0.00, $totals->saldoAFavorAnterior);
    }

    public function test_compute_totals_for_no_arrastra_si_mes_previo_no_tiene_snapshot_activo(): void
    {
        // Mes previo existe pero sin snapshot (abierto, nunca declarado).
        FiscalPeriod::factory()->forMonth(2026, 1)->create();
        $currentPeriod = FiscalPeriod::factory()->forMonth(2026, 2)->create();

        $totals = $this->service->computeTotalsFor($currentPeriod);

        $this->assertEquals(0.00, $totals->saldoAFavorAnterior);
    }

    public function test_compute_totals_for_ignora_snapshot_supersedido_del_mes_previo(): void
    {
        // Mes previo: snapshot supersedido + snapshot activo con saldos distintos.
        $prevPeriod = FiscalPeriod::factory()->forMonth(2026, 1)->create();

        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($prevPeriod)
            ->superseded()
            ->create(['saldo_a_favor_siguiente' => 500.00]);

        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($prevPeriod)
            ->create(['saldo_a_favor_siguiente' => 800.00]);

        $currentPeriod = FiscalPeriod::factory()->forMonth(2026, 2)->create();

        $totals = $this->service->computeTotalsFor($currentPeriod);

        // Se lee el snapshot ACTIVO, no el supersedido.
        $this->assertEquals(800.00, $totals->saldoAFavorAnterior);
    }

    public function test_compute_totals_for_rollover_diciembre_a_enero_siguiente_ano(): void
    {
        // Diciembre 2025 con snapshot activo.
        $decPeriod = FiscalPeriod::factory()->forMonth(2025, 12)->create();
        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($decPeriod)
            ->create(['saldo_a_favor_siguiente' => 333.33]);

        // Enero 2026 debe leer diciembre 2025.
        $janPeriod = FiscalPeriod::factory()->forMonth(2026, 1)->create();

        $totals = $this->service->computeTotalsFor($janPeriod);

        $this->assertEquals(333.33, $totals->saldoAFavorAnterior);
    }

    // ═══════════════════════════════════════════════════════
    // 2. declare() — happy path + precondiciones
    // ═══════════════════════════════════════════════════════

    public function test_declare_crea_snapshot_y_cierra_periodo_en_una_transaccion(): void
    {
        $user = $this->makeUser();
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->create();

        $snapshot = $this->service->declare(
            period: $period,
            declaredBy: $user,
            siisarAcuse: 'ACUSE-123456',
            notes: 'Declaración mensual Feb 2026',
        );

        // Snapshot persistido con los 12 totales + metadata
        $this->assertDatabaseHas('isv_monthly_declarations', [
            'id' => $snapshot->id,
            'fiscal_period_id' => $period->id,
            'declared_by_user_id' => $user->id,
            'siisar_acuse_number' => 'ACUSE-123456',
            'notes' => 'Declaración mensual Feb 2026',
            'superseded_at' => null,
        ]);

        // FiscalPeriod cerrado en la misma transacción
        $period->refresh();
        $this->assertNotNull($period->declared_at);
        $this->assertEquals($user->id, $period->declared_by);
        $this->assertTrue($period->isClosed());
    }

    public function test_declare_lanza_excepcion_si_ya_existe_snapshot_activo(): void
    {
        $user = $this->makeUser();
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->create();

        // Primera declaración exitosa
        $firstSnapshot = $this->service->declare($period, $user);

        // Segunda declaración debe fallar
        try {
            $this->service->declare($period, $user);
            $this->fail('Esperaba DeclaracionIsvYaExisteException');
        } catch (DeclaracionIsvYaExisteException $e) {
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(2, $e->periodMonth);
            $this->assertEquals($firstSnapshot->id, $e->existingDeclarationId);
        }

        // Solo existe un snapshot (el primero)
        $this->assertEquals(1, IsvMonthlyDeclaration::count());
    }

    public function test_declare_rollback_si_fiscal_period_service_falla(): void
    {
        $user = $this->makeUser();
        // Período ya declarado (caso raro pero posible si alguien declaró por fuera
        // del service — ej: manualmente en un seeder). declare() del snapshot debe
        // revertir todo cuando FiscalPeriodService::declare lance YaDeclaradoException.
        $period = FiscalPeriod::factory()
            ->forMonth(2026, 2)
            ->create(['declared_at' => now(), 'declared_by' => $user->id]);

        try {
            $this->service->declare($period, $user);
            $this->fail('Esperaba PeriodoFiscalYaDeclaradoException');
        } catch (\Throwable $e) {
            // Cualquiera que lance — lo importante es que no haya snapshot huérfano.
        }

        // Invariante: ningún snapshot creado porque la tx revirtió.
        $this->assertEquals(0, IsvMonthlyDeclaration::count());
    }

    // ═══════════════════════════════════════════════════════
    // 3. redeclare() — rectificativa
    // ═══════════════════════════════════════════════════════

    public function test_redeclare_marca_snapshot_previo_supersedido_y_crea_nuevo_activo(): void
    {
        $user = $this->makeUser();
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->create();

        // FiscalPeriod::isOpen() compara `reopened_at > declared_at` con `>` estricto.
        // En producción pasan horas/días entre declare → reopen → redeclare, así que
        // los timestamps SIEMPRE son distintos. En un test, las tres operaciones
        // ocurren en microsegundos; sin estos travelTo, los timestamps colapsan en
        // el mismo segundo (MySQL datetime trunca a segundos) y la comparación
        // estricta retorna false → isClosed() erróneamente true → redeclare fallaría
        // al re-cerrar. El travelTo simula el paso de tiempo real del flujo operativo.

        // Ciclo completo: declarar → reabrir → redeclarar (cada fase en segundo distinto)
        $this->travelTo('2026-04-19 10:00:00');
        $original = $this->service->declare($period, $user, notes: 'Original');

        $this->travelTo('2026-04-19 10:00:05');
        $this->fiscalPeriodService->reopen(
            period: $period->fresh(),
            reopenedBy: $user,
            reason: 'Error en retenciones',
        );

        $this->travelTo('2026-04-19 10:00:10');
        $rectificativa = $this->service->redeclare(
            period: $period->fresh(),
            declaredBy: $user,
            siisarAcuse: 'ACUSE-RECT-789',
            notes: 'Rectificativa Feb 2026',
        );

        // Snapshot original: supersedido
        $original->refresh();
        $this->assertNotNull($original->superseded_at);
        $this->assertEquals($user->id, $original->superseded_by_user_id);
        $this->assertTrue($original->isSuperseded());

        // Rectificativa: activa, con metadata nueva
        $this->assertTrue($rectificativa->isActive());
        $this->assertEquals('ACUSE-RECT-789', $rectificativa->siisar_acuse_number);
        $this->assertEquals('Rectificativa Feb 2026', $rectificativa->notes);

        // El período vuelve a estar cerrado (declared_at > reopened_at)
        $period->refresh();
        $this->assertTrue($period->isClosed());

        // Solo un snapshot activo por período
        $activeCount = IsvMonthlyDeclaration::forFiscalPeriod($period->id)
            ->active()
            ->count();
        $this->assertEquals(1, $activeCount);
    }

    public function test_redeclare_lanza_excepcion_si_periodo_no_fue_reabierto(): void
    {
        $user = $this->makeUser();
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->create();

        // Declaración original sin reabrir — redeclare no puede ejecutarse.
        $this->service->declare($period, $user);

        try {
            $this->service->redeclare(period: $period->fresh(), declaredBy: $user);
            $this->fail('Esperaba PeriodoFiscalNoReabiertoException');
        } catch (PeriodoFiscalNoReabiertoException $e) {
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(2, $e->periodMonth);
        }

        // Sigue habiendo solo el snapshot original, sin cambios.
        $this->assertEquals(1, IsvMonthlyDeclaration::count());
        $this->assertEquals(1, IsvMonthlyDeclaration::active()->count());
    }

    public function test_redeclare_lanza_excepcion_si_no_existe_snapshot_activo_previo(): void
    {
        $user = $this->makeUser();

        // Período reabierto pero SIN declaración original (caso anómalo de configuración).
        // Creamos un período ya reabierto manualmente sin pasar por declare().
        $period = FiscalPeriod::factory()
            ->forMonth(2026, 2)
            ->create([
                'declared_at' => now()->subDay(),
                'declared_by' => $user->id,
                'reopened_at' => now(),
                'reopened_by' => $user->id,
                'reopen_reason' => 'Test',
            ]);

        try {
            $this->service->redeclare(period: $period, declaredBy: $user);
            $this->fail('Esperaba SnapshotActivoNoExisteException');
        } catch (SnapshotActivoNoExisteException $e) {
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(2, $e->periodMonth);
        }

        $this->assertEquals(0, IsvMonthlyDeclaration::count());
    }

    // ═══════════════════════════════════════════════════════
    // 4. Cadena de saldo arrastrado entre snapshots
    // ═══════════════════════════════════════════════════════

    public function test_declare_usa_saldo_a_favor_del_snapshot_previo_al_computar(): void
    {
        $user = $this->makeUser();

        // Enero 2026 declarado con saldo a favor de 500
        $janPeriod = FiscalPeriod::factory()->forMonth(2026, 1)->create();
        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($janPeriod)
            ->create([
                'saldo_a_favor_siguiente' => 500.00,
                'isv_a_pagar' => 0,
            ]);
        // Cerrar el período fiscal de enero también (para consistencia)
        $janPeriod->update(['declared_at' => now()->subMonth(), 'declared_by' => $user->id]);

        // Febrero 2026: retenciones de 100, sin ventas ni compras
        $febPeriod = FiscalPeriod::factory()->forMonth(2026, 2)->create();
        IsvRetentionReceived::factory()->create([
            'period_year' => 2026,
            'period_month' => 2,
            'amount' => 100.00,
        ]);

        $snapshot = $this->service->declare($febPeriod, $user);

        // neto = 0 − 0 − 100 − 500 = -600 → saldo_siguiente 600
        $this->assertEquals(500.00, (float) $snapshot->saldo_a_favor_anterior);
        $this->assertEquals(100.00, (float) $snapshot->isv_retenciones_recibidas);
        $this->assertEquals(0.00, (float) $snapshot->isv_a_pagar);
        $this->assertEquals(600.00, (float) $snapshot->saldo_a_favor_siguiente);
    }
}

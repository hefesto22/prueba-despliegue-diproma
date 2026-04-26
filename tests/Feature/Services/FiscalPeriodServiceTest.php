<?php

namespace Tests\Feature\Services;

use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\User;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalCerradoException;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalNoConfiguradoException;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalYaDeclaradoException;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalYaReabiertoException;
use App\Services\FiscalPeriods\FiscalPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cubre la lógica central de períodos fiscales:
 *   - Lazy-create de períodos (current / forInvoice / forDate)
 *   - Estado (isOpen / canVoidInvoice / assertCanVoidInvoice)
 *   - Comandos (declare / reopen) y sus excepciones tipadas
 *
 * Referencia de reglas: Acuerdo SAR 481-2017 (CAI) + 189-2014 (rectificativas).
 */
class FiscalPeriodServiceTest extends TestCase
{
    use RefreshDatabase;

    private FiscalPeriodService $service;

    private CompanySetting $company;

    private Establishment $matriz;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');

        // Configuración base: tracking fiscal inicia 2026-01-01.
        // Cualquier factura antes de esa fecha se considera pre-tracking.
        //
        // NOTA: pasamos string '2026-01-01' en lugar de CarbonImmutable para
        // evitar ambigüedades con el cast 'immutable_date' en el INSERT — Laravel
        // lo hidrata como CarbonImmutable al leer desde DB.
        $this->company = CompanySetting::factory()->create([
            'fiscal_period_start' => '2026-01-01',
        ]);

        // Matriz asociada a ESTA company. Necesaria porque Invoice::factory()
        // encadena Establishment::factory(), y ése a su vez crea una NUEVA
        // CompanySetting — duplicando el registro e invalidando el cache por el
        // observer `saved`. Inyectando matriz_id en cada Invoice evitamos la cadena.
        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        // Cache::put DEBE ir DESPUÉS de crear matriz: el saved observer de
        // CompanySetting ya se disparó, así que este put es el estado final.
        $this->refreshCompanyCache();

        $this->service = app(FiscalPeriodService::class);
    }

    /**
     * Restaurar el cache de company_settings con la instancia conocida del test.
     *
     * Necesario después de cualquier operación que guarde una CompanySetting
     * (el observer `saved` en el modelo dispara Cache::forget). Sin esto,
     * `CompanySetting::current()` haría cache miss y podría devolver una
     * instancia sin `fiscal_period_start`.
     */
    private function refreshCompanyCache(): void
    {
        Cache::put('company_settings', $this->company->fresh(), 60 * 60 * 24);
    }

    /**
     * Crear una Invoice vinculada al establishment matriz del test.
     *
     * Evita que el factory encadene Establishment::factory() y por ende cree
     * una CompanySetting duplicada que contamine el cache.
     */
    private function makeInvoice(array $attrs = []): Invoice
    {
        $invoice = Invoice::factory()->create(array_merge([
            'establishment_id' => $this->matriz->id,
        ], $attrs));

        // El save del Invoice NO invalida company_settings (diferente modelo),
        // pero por paranoia mantenemos el cache fresco para aislar los tests
        // de cualquier efecto colateral futuro.
        $this->refreshCompanyCache();

        return $invoice;
    }

    // ─── current() / forInvoice() / forDate() ─────────────────────────

    public function test_current_lazy_creates_period_for_current_month_if_not_exists(): void
    {
        $this->assertEquals(0, FiscalPeriod::count());

        $period = $this->service->current();

        $this->assertEquals(1, FiscalPeriod::count());
        $this->assertEquals((int) now()->year, $period->period_year);
        $this->assertEquals((int) now()->month, $period->period_month);
        $this->assertNull($period->declared_at);
        $this->assertTrue($period->isOpen());
    }

    public function test_current_returns_existing_period_if_already_present(): void
    {
        $existing = FiscalPeriod::factory()
            ->forMonth((int) now()->year, (int) now()->month)
            ->create();

        $period = $this->service->current();

        $this->assertEquals($existing->id, $period->id);
        $this->assertEquals(1, FiscalPeriod::count());
    }

    public function test_current_throws_if_fiscal_period_start_not_configured(): void
    {
        $this->company->update(['fiscal_period_start' => null]);
        $this->refreshCompanyCache();

        $this->expectException(PeriodoFiscalNoConfiguradoException::class);

        $this->service->current();
    }

    public function test_for_invoice_returns_period_matching_invoice_date(): void
    {
        $invoice = $this->makeInvoice([
            'invoice_date' => '2026-03-15',
        ]);

        $period = $this->service->forInvoice($invoice);

        $this->assertEquals(2026, $period->period_year);
        $this->assertEquals(3, $period->period_month);
    }

    public function test_for_date_lazy_creates_period_for_past_month(): void
    {
        $period = $this->service->forDate(CarbonImmutable::create(2026, 2, 10));

        $this->assertEquals(2026, $period->period_year);
        $this->assertEquals(2, $period->period_month);
        $this->assertTrue($period->isOpen());
    }

    // ─── isOpen() ────────────────────────────────────────────────────

    public function test_is_open_returns_true_when_period_does_not_exist(): void
    {
        // Sin registro previo: el período se considera abierto (lazy).
        $this->assertTrue($this->service->isOpen(2026, 2));
        // Consulta pura: NO debe crear el período.
        $this->assertEquals(0, FiscalPeriod::count());
    }

    public function test_is_open_returns_false_when_period_is_declared(): void
    {
        $user = User::factory()->create();
        FiscalPeriod::factory()
            ->forMonth(2026, 1)
            ->declared($user)
            ->create();

        $this->assertFalse($this->service->isOpen(2026, 1));
    }

    public function test_is_open_returns_true_when_period_is_reopened_after_declared(): void
    {
        $user = User::factory()->create();
        FiscalPeriod::factory()
            ->forMonth(2026, 1)
            ->reopened($user, 'Rectificativa solicitada')
            ->create();

        $this->assertTrue($this->service->isOpen(2026, 1));
    }

    // ─── canVoidInvoice() ────────────────────────────────────────────

    public function test_can_void_invoice_false_when_invoice_is_already_void(): void
    {
        $invoice = $this->makeInvoice([
            'invoice_date' => '2026-03-10',
            'is_void' => true,
        ]);

        $this->assertFalse($this->service->canVoidInvoice($invoice));
    }

    public function test_can_void_invoice_false_when_fiscal_period_start_not_configured(): void
    {
        $invoice = $this->makeInvoice([
            'invoice_date' => '2026-03-10',
        ]);

        // Anular fiscal_period_start DESPUÉS de crear el invoice para que el
        // chain de factories no afecte. El refresh garantiza cache consistente.
        $this->company->update(['fiscal_period_start' => null]);
        $this->refreshCompanyCache();

        $this->assertFalse($this->service->canVoidInvoice($invoice));
    }

    public function test_can_void_invoice_false_when_invoice_date_before_fiscal_period_start(): void
    {
        // fiscal_period_start = 2026-01-01, invoice es 2025-12-15 → pre-tracking
        $invoice = $this->makeInvoice([
            'invoice_date' => '2025-12-15',
        ]);

        $this->assertFalse($this->service->canVoidInvoice($invoice));
    }

    public function test_can_void_invoice_false_when_period_is_declared(): void
    {
        $user = User::factory()->create();
        FiscalPeriod::factory()
            ->forMonth(2026, 3)
            ->declared($user)
            ->create();

        $invoice = $this->makeInvoice([
            'invoice_date' => '2026-03-10',
        ]);

        $this->assertFalse($this->service->canVoidInvoice($invoice));
    }

    public function test_can_void_invoice_true_when_period_open_and_invoice_valid(): void
    {
        $invoice = $this->makeInvoice([
            'invoice_date' => '2026-03-10',
        ]);

        $this->assertTrue($this->service->canVoidInvoice($invoice));
    }

    public function test_can_void_invoice_true_when_period_reopened_after_declaration(): void
    {
        $user = User::factory()->create();
        FiscalPeriod::factory()
            ->forMonth(2026, 3)
            ->reopened($user, 'Corrección por error en línea 5')
            ->create();

        $invoice = $this->makeInvoice([
            'invoice_date' => '2026-03-10',
        ]);

        $this->assertTrue($this->service->canVoidInvoice($invoice));
    }

    // ─── assertCanVoidInvoice() ──────────────────────────────────────

    public function test_assert_can_void_invoice_throws_no_configurado_if_fiscal_period_start_null(): void
    {
        $invoice = $this->makeInvoice([
            'invoice_date' => '2026-03-10',
        ]);

        $this->company->update(['fiscal_period_start' => null]);
        $this->refreshCompanyCache();

        $this->expectException(PeriodoFiscalNoConfiguradoException::class);

        $this->service->assertCanVoidInvoice($invoice);
    }

    public function test_assert_can_void_invoice_throws_cerrado_if_invoice_pre_tracking(): void
    {
        $invoice = $this->makeInvoice([
            'invoice_date' => '2025-11-20',
            'invoice_number' => '001-001-01-00000042',
        ]);

        try {
            $this->service->assertCanVoidInvoice($invoice);
            $this->fail('Se esperaba PeriodoFiscalCerradoException.');
        } catch (PeriodoFiscalCerradoException $e) {
            $this->assertEquals(2025, $e->periodYear);
            $this->assertEquals(11, $e->periodMonth);
            // En ISV.3a se generalizó la excepción: ahora expone `documentLabel`
            // (string human-readable) en vez de `invoiceNumber` específico,
            // para reusarse desde Observers de Purchase e IsvRetentionReceived.
            $this->assertEquals('la factura 001-001-01-00000042', $e->documentLabel);
        }
    }

    public function test_assert_can_void_invoice_throws_cerrado_if_period_declared(): void
    {
        $user = User::factory()->create();
        FiscalPeriod::factory()
            ->forMonth(2026, 3)
            ->declared($user)
            ->create();

        $invoice = $this->makeInvoice([
            'invoice_date' => '2026-03-10',
        ]);

        $this->expectException(PeriodoFiscalCerradoException::class);

        $this->service->assertCanVoidInvoice($invoice);
    }

    public function test_assert_can_void_invoice_passes_silently_when_period_open(): void
    {
        $invoice = $this->makeInvoice([
            'invoice_date' => '2026-03-10',
        ]);

        // Si el período está abierto, no debe lanzar nada.
        $this->service->assertCanVoidInvoice($invoice);
        $this->assertTrue(true); // llegamos aquí = ok
    }

    // ─── declare() ───────────────────────────────────────────────────

    public function test_declare_marks_period_as_declared(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->create();
        $user = User::factory()->create();

        $this->service->declare($period, $user, 'Declaración de febrero presentada por ventanilla');

        $period->refresh();
        $this->assertNotNull($period->declared_at);
        $this->assertEquals($user->id, $period->declared_by);
        $this->assertEquals(
            'Declaración de febrero presentada por ventanilla',
            $period->declaration_notes,
        );
        $this->assertTrue($period->isClosed());
    }

    public function test_declare_throws_if_period_already_declared_and_not_reopened(): void
    {
        $user = User::factory()->create();
        $period = FiscalPeriod::factory()
            ->forMonth(2026, 2)
            ->declared($user)
            ->create();

        $this->expectException(PeriodoFiscalYaDeclaradoException::class);

        $this->service->declare($period, $user, 'Intento doble');
    }

    public function test_declare_succeeds_on_reopened_period_redeclaring_it(): void
    {
        $user = User::factory()->create();
        $period = FiscalPeriod::factory()
            ->forMonth(2026, 2)
            ->reopened($user, 'Rectificativa por factura mal aplicada')
            ->create();

        // Un período reabierto está "abierto" — puede volver a declararse.
        $this->service->declare($period, $user, 'Re-declaración rectificativa');

        $period->refresh();
        $this->assertTrue($period->isClosed());
        $this->assertEquals('Re-declaración rectificativa', $period->declaration_notes);
    }

    // ─── reopen() ────────────────────────────────────────────────────

    public function test_reopen_marks_period_as_reopened(): void
    {
        $declarer = User::factory()->create();
        $admin = User::factory()->create();
        $period = FiscalPeriod::factory()
            ->forMonth(2026, 1)
            ->declared($declarer)
            ->create();

        $this->service->reopen($period, $admin, 'Error detectado en factura 00015');

        $period->refresh();
        $this->assertNotNull($period->reopened_at);
        $this->assertEquals($admin->id, $period->reopened_by);
        $this->assertEquals('Error detectado en factura 00015', $period->reopen_reason);
        $this->assertTrue($period->isOpen());
        $this->assertTrue($period->wasReopened());
    }

    public function test_reopen_throws_if_period_is_still_open(): void
    {
        $admin = User::factory()->create();
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->create();

        $this->expectException(PeriodoFiscalYaReabiertoException::class);

        $this->service->reopen($period, $admin, 'Motivo cualquiera');
    }

    public function test_reopen_throws_if_reason_is_empty(): void
    {
        $user = User::factory()->create();
        $period = FiscalPeriod::factory()
            ->forMonth(2026, 1)
            ->declared($user)
            ->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('motivo de reapertura');

        $this->service->reopen($period, $user, '   ');
    }

    // ─── listOverdue() — widget + job alimentador ────────────────────
    //
    // `listOverdue()` es una QUERY PURA: NO crea períodos. La creación de
    // registros para meses sin actividad la hace `ensureOverduePeriodsExist()`,
    // invocado desde el scheduler diario. Los tests de `listOverdue()` que
    // requieren datos pre-cargados llaman al command explícitamente para
    // reproducir el flujo real del sistema.

    public function test_list_overdue_returns_empty_if_fiscal_period_start_not_configured(): void
    {
        $this->company->update(['fiscal_period_start' => null]);
        $this->refreshCompanyCache();

        $result = $this->service->listOverdue();

        $this->assertTrue($result->isEmpty(), 'Sin configuración debe degradar silenciosamente.');
    }

    public function test_list_overdue_excludes_current_month(): void
    {
        // Fijar "hoy" en abril 2026. Tracking desde enero 2026.
        // Vencidos esperados: enero, febrero, marzo. Abril (en curso) NO.
        CarbonImmutable::setTestNow('2026-04-17');

        $this->service->ensureOverduePeriodsExist();
        $overdue = $this->service->listOverdue();

        $this->assertCount(3, $overdue);
        $labels = $overdue->map(fn ($p) => "{$p->period_year}-{$p->period_month}")->all();
        $this->assertEquals(['2026-1', '2026-2', '2026-3'], $labels);

        CarbonImmutable::setTestNow();
    }

    public function test_list_overdue_is_pure_does_not_create_periods(): void
    {
        // Contrato: listOverdue NO debe crear registros (CQRS — es una query).
        // El command equivalente (ensureOverduePeriodsExist) sí lo hace.
        CarbonImmutable::setTestNow('2026-04-17');

        $this->assertEquals(0, FiscalPeriod::count(), 'Pre: ningún período creado.');

        $result = $this->service->listOverdue();

        $this->assertTrue($result->isEmpty(), 'Sin registros persistidos, listOverdue retorna vacío.');
        $this->assertEquals(0, FiscalPeriod::count(), 'Post: listOverdue NO crea registros.');

        CarbonImmutable::setTestNow();
    }

    public function test_list_overdue_excludes_declared_periods(): void
    {
        CarbonImmutable::setTestNow('2026-04-17');

        $user = User::factory()->create();
        FiscalPeriod::factory()->forMonth(2026, 1)->declared($user)->create();
        FiscalPeriod::factory()->forMonth(2026, 2)->declared($user)->create();

        // Poblar marzo (único mes sin factory pre-existente) vía el command.
        $this->service->ensureOverduePeriodsExist();

        $overdue = $this->service->listOverdue();

        $this->assertCount(1, $overdue, 'Solo marzo queda pendiente.');
        $this->assertEquals(3, $overdue->first()->period_month);

        CarbonImmutable::setTestNow();
    }

    public function test_list_overdue_includes_reopened_periods(): void
    {
        CarbonImmutable::setTestNow('2026-04-17');

        $user = User::factory()->create();

        // Enero declarado → cerrado (no aparece).
        FiscalPeriod::factory()->forMonth(2026, 1)->declared($user)->create();

        // Febrero declarado y luego reabierto → abierto de nuevo (aparece).
        $febrero = FiscalPeriod::factory()->forMonth(2026, 2)->declared($user)->create();
        $febrero->update([
            'reopened_at' => now(),
            'reopened_by' => $user->id,
            'reopen_reason' => 'Rectificativa',
        ]);

        // Poblar marzo (nunca declarado).
        $this->service->ensureOverduePeriodsExist();

        $overdue = $this->service->listOverdue();

        // Febrero (reabierto) + Marzo (nunca declarado) = 2.
        $this->assertCount(2, $overdue);
        $months = $overdue->map(fn ($p) => $p->period_month)->sort()->values()->all();
        $this->assertEquals([2, 3], $months);

        CarbonImmutable::setTestNow();
    }

    public function test_list_overdue_returns_empty_when_tracking_starts_this_month(): void
    {
        // Tracking empieza HOY (abril 2026). No hay meses vencidos todavía.
        CarbonImmutable::setTestNow('2026-04-17');
        $this->company->update(['fiscal_period_start' => '2026-04-01']);
        $this->refreshCompanyCache();

        $overdue = $this->service->listOverdue();

        $this->assertTrue($overdue->isEmpty());

        CarbonImmutable::setTestNow();
    }

    public function test_list_overdue_orders_by_oldest_first(): void
    {
        CarbonImmutable::setTestNow('2026-04-17');

        $this->service->ensureOverduePeriodsExist();
        $overdue = $this->service->listOverdue();

        // El primero debe ser el más antiguo (enero), último el más reciente (marzo).
        $this->assertEquals(1, $overdue->first()->period_month);
        $this->assertEquals(3, $overdue->last()->period_month);

        CarbonImmutable::setTestNow();
    }

    // ─── ensureOverduePeriodsExist() — command del scheduler ──────────

    public function test_ensure_overdue_periods_creates_missing_past_months(): void
    {
        CarbonImmutable::setTestNow('2026-04-17');

        $this->assertEquals(0, FiscalPeriod::count(), 'Pre: ningún período creado.');

        $this->service->ensureOverduePeriodsExist();

        // Enero, febrero, marzo. Abril (en curso) NO se crea — aún no vencido.
        $this->assertEquals(3, FiscalPeriod::count());
        $this->assertTrue(FiscalPeriod::forMonth(2026, 1)->exists());
        $this->assertTrue(FiscalPeriod::forMonth(2026, 2)->exists());
        $this->assertTrue(FiscalPeriod::forMonth(2026, 3)->exists());
        $this->assertFalse(FiscalPeriod::forMonth(2026, 4)->exists());

        CarbonImmutable::setTestNow();
    }

    public function test_ensure_overdue_periods_is_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-04-17');

        $this->service->ensureOverduePeriodsExist();
        $this->service->ensureOverduePeriodsExist();
        $this->service->ensureOverduePeriodsExist();

        // firstOrCreate evita duplicados aún con múltiples invocaciones
        // (garantizado por UNIQUE(period_year, period_month)).
        $this->assertEquals(3, FiscalPeriod::count());

        CarbonImmutable::setTestNow();
    }

    public function test_ensure_overdue_periods_does_nothing_if_not_configured(): void
    {
        $this->company->update(['fiscal_period_start' => null]);
        $this->refreshCompanyCache();

        // Degradación silenciosa: sin configuración, el command no hace nada
        // (no lanza excepción — el scheduler puede llamarlo en cualquier momento).
        $this->service->ensureOverduePeriodsExist();

        $this->assertEquals(0, FiscalPeriod::count());
    }

    public function test_ensure_overdue_periods_does_nothing_when_tracking_starts_this_month(): void
    {
        // Si fiscal_period_start es el mes actual, no hay meses vencidos para poblar.
        CarbonImmutable::setTestNow('2026-04-17');
        $this->company->update(['fiscal_period_start' => '2026-04-01']);
        $this->refreshCompanyCache();

        $this->service->ensureOverduePeriodsExist();

        $this->assertEquals(0, FiscalPeriod::count());

        CarbonImmutable::setTestNow();
    }

    // ─── countOverdue() — badge del navigation ────────────────────────

    public function test_count_overdue_matches_list_overdue_size(): void
    {
        CarbonImmutable::setTestNow('2026-04-17');

        $this->service->ensureOverduePeriodsExist();

        // countOverdue y listOverdue comparten los mismos filtros — el count
        // debe coincidir con el tamaño de la lista para cualquier estado.
        $this->assertEquals($this->service->listOverdue()->count(), $this->service->countOverdue());

        CarbonImmutable::setTestNow();
    }

    public function test_count_overdue_returns_zero_when_not_configured(): void
    {
        $this->company->update(['fiscal_period_start' => null]);
        $this->refreshCompanyCache();

        // El memo de la instancia aún es null (setUp no llamó countOverdue),
        // así que el primer acceso consulta CompanySetting y devuelve 0.
        $this->assertEquals(0, $this->service->countOverdue());
    }

    // ─── assertPeriodIsOpen() — gate genérico por año/mes ─────────────
    //
    // Usado por Observers de documentos fiscales que capturan el período
    // directamente en columnas (ej: IsvRetentionReceived con period_year/month).

    public function test_assert_period_is_open_does_nothing_if_fiscal_period_start_null(): void
    {
        // Default allow cuando el módulo fiscal no está habilitado.
        $this->company->update(['fiscal_period_start' => null]);
        $this->refreshCompanyCache();

        // Incluso un período "declarado" no debe bloquear, porque el módulo
        // fiscal está apagado — no hay concepto de "cerrado".
        $this->service->assertPeriodIsOpen(2026, 3, 'la retención ISV #1');
        $this->assertTrue(true); // llegamos aquí = ok
    }

    public function test_assert_period_is_open_passes_silently_when_period_open(): void
    {
        // Sin FiscalPeriod previo → se considera abierto (consistente con isOpen()).
        $this->service->assertPeriodIsOpen(2026, 3, 'la retención ISV #1');
        $this->assertTrue(true);
    }

    public function test_assert_period_is_open_passes_silently_when_period_reopened(): void
    {
        $user = User::factory()->create();
        FiscalPeriod::factory()
            ->forMonth(2026, 3)
            ->reopened($user, 'Rectificativa solicitada')
            ->create();

        $this->service->assertPeriodIsOpen(2026, 3, 'la retención ISV #1');
        $this->assertTrue(true);
    }

    public function test_assert_period_is_open_throws_when_period_declared(): void
    {
        $user = User::factory()->create();
        FiscalPeriod::factory()
            ->forMonth(2026, 3)
            ->declared($user)
            ->create();

        try {
            $this->service->assertPeriodIsOpen(2026, 3, 'la retención ISV #42');
            $this->fail('Se esperaba PeriodoFiscalCerradoException.');
        } catch (PeriodoFiscalCerradoException $e) {
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(3, $e->periodMonth);
            $this->assertEquals('la retención ISV #42', $e->documentLabel);
        }
    }

    // ─── assertDateIsInOpenPeriod() — gate genérico por fecha ─────────
    //
    // Usado por Observers de documentos fiscales cuyo período se deriva
    // de una columna de fecha (ej: Purchase con `date`, Invoice con `invoice_date`).

    public function test_assert_date_is_in_open_period_does_nothing_if_fiscal_period_start_null(): void
    {
        $this->company->update(['fiscal_period_start' => null]);
        $this->refreshCompanyCache();

        $this->service->assertDateIsInOpenPeriod(
            CarbonImmutable::create(2026, 3, 15),
            'la compra RI-2026-0001',
        );
        $this->assertTrue(true);
    }

    public function test_assert_date_is_in_open_period_throws_when_date_is_pre_tracking(): void
    {
        // fiscal_period_start = 2026-01-01 (setUp); fecha anterior = cerrado.
        try {
            $this->service->assertDateIsInOpenPeriod(
                CarbonImmutable::create(2025, 12, 20),
                'la compra RI-2025-0099',
            );
            $this->fail('Se esperaba PeriodoFiscalCerradoException por pre-tracking.');
        } catch (PeriodoFiscalCerradoException $e) {
            $this->assertEquals(2025, $e->periodYear);
            $this->assertEquals(12, $e->periodMonth);
            $this->assertEquals('la compra RI-2025-0099', $e->documentLabel);
        }
    }

    public function test_assert_date_is_in_open_period_throws_when_period_of_date_declared(): void
    {
        $user = User::factory()->create();
        FiscalPeriod::factory()
            ->forMonth(2026, 3)
            ->declared($user)
            ->create();

        try {
            $this->service->assertDateIsInOpenPeriod(
                CarbonImmutable::create(2026, 3, 15),
                'la compra COMP-2026-00033',
            );
            $this->fail('Se esperaba PeriodoFiscalCerradoException por período declarado.');
        } catch (PeriodoFiscalCerradoException $e) {
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(3, $e->periodMonth);
            $this->assertEquals('la compra COMP-2026-00033', $e->documentLabel);
        }
    }

    public function test_assert_date_is_in_open_period_passes_when_period_open(): void
    {
        $this->service->assertDateIsInOpenPeriod(
            CarbonImmutable::create(2026, 3, 15),
            'la compra COMP-2026-00033',
        );
        $this->assertTrue(true);
    }
}

<?php

namespace Tests\Feature\FiscalPeriods;

use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\FiscalPeriod;
use App\Models\IsvRetentionReceived;
use App\Models\User;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalCerradoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cubre el IsvRetentionReceivedObserver (ISV.3a): inmutabilidad fiscal de
 * las retenciones ISV cuyo período ya fue declarado al SAR.
 *
 * Diferencia con PurchaseObserver: el modelo expone el período directamente
 * en columnas `period_year` / `period_month` (no derivado de una fecha). Por
 * eso el Observer usa `assertPeriodIsOpen()` en vez de `assertDateIsInOpenPeriod()`.
 *
 * Reglas bajo test:
 *   - creating: retención nueva en período declarado → bloqueo.
 *   - updating: retención cuyo período original está declarado → bloqueo.
 *   - updating: mover el período hacia uno declarado → bloqueo.
 *   - deleting: borrar retención de período declarado → bloqueo.
 *   - Default allow: fiscal_period_start NULL → no bloquea.
 *   - Default allow: período reabierto → permite (escape hatch legítimo).
 */
class IsvRetentionReceivedLockedInClosedPeriodTest extends TestCase
{
    use RefreshDatabase;

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
    }

    private function refreshCompanyCache(): void
    {
        Cache::put('company_settings', $this->company->fresh(), 60 * 60 * 24);
    }

    private function makeRetention(array $attrs = []): IsvRetentionReceived
    {
        return IsvRetentionReceived::factory()->create(array_merge([
            'establishment_id' => $this->matriz->id,
        ], $attrs));
    }

    private function closePeriod(int $year, int $month): FiscalPeriod
    {
        $user = User::factory()->create();

        return FiscalPeriod::factory()
            ->forMonth($year, $month)
            ->declared($user)
            ->create();
    }

    // ─── creating ────────────────────────────────────────────────────

    public function test_creating_retention_in_open_period_succeeds(): void
    {
        $retention = $this->makeRetention([
            'period_year' => 2026,
            'period_month' => 3,
        ]);

        $this->assertNotNull($retention->id);
    }

    public function test_creating_retention_in_declared_period_is_blocked(): void
    {
        $this->closePeriod(2026, 3);

        try {
            $this->makeRetention([
                'period_year' => 2026,
                'period_month' => 3,
            ]);
            $this->fail('Se esperaba PeriodoFiscalCerradoException.');
        } catch (PeriodoFiscalCerradoException $e) {
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(3, $e->periodMonth);
        }

        $this->assertEquals(0, IsvRetentionReceived::count(), 'La retención no debe persistirse.');
    }

    public function test_creating_retention_in_reopened_period_is_allowed(): void
    {
        $user = User::factory()->create();
        FiscalPeriod::factory()
            ->forMonth(2026, 3)
            ->reopened($user, 'Rectificativa por retención omitida')
            ->create();

        $retention = $this->makeRetention([
            'period_year' => 2026,
            'period_month' => 3,
        ]);

        $this->assertNotNull($retention->id);
    }

    public function test_creating_retention_does_nothing_when_module_not_configured(): void
    {
        $this->company->update(['fiscal_period_start' => null]);
        $this->refreshCompanyCache();

        $retention = $this->makeRetention([
            'period_year' => 2026,
            'period_month' => 3,
        ]);

        $this->assertNotNull($retention->id);
    }

    // ─── updating ────────────────────────────────────────────────────

    public function test_updating_retention_in_open_period_succeeds(): void
    {
        $retention = $this->makeRetention([
            'period_year' => 2026,
            'period_month' => 3,
        ]);

        $retention->update(['notes' => 'Anotación adicional']);

        $this->assertEquals('Anotación adicional', $retention->fresh()->notes);
    }

    public function test_updating_retention_in_declared_period_is_blocked(): void
    {
        $retention = $this->makeRetention([
            'period_year' => 2026,
            'period_month' => 3,
        ]);

        $this->closePeriod(2026, 3);

        try {
            $retention->update(['amount' => 9999.99]);
            $this->fail('Se esperaba PeriodoFiscalCerradoException.');
        } catch (PeriodoFiscalCerradoException $e) {
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(3, $e->periodMonth);
        }

        // El monto original no debe haber cambiado.
        $this->assertNotEquals('9999.99', $retention->fresh()->amount);
    }

    public function test_updating_retention_cannot_move_to_declared_period(): void
    {
        // Creamos en marzo (abierto), intentamos mover a febrero (declarado).
        $this->closePeriod(2026, 2);
        $retention = $this->makeRetention([
            'period_year' => 2026,
            'period_month' => 3,
        ]);

        try {
            $retention->update([
                'period_year' => 2026,
                'period_month' => 2,
            ]);
            $this->fail('Se esperaba bloqueo al mover retención a período declarado.');
        } catch (PeriodoFiscalCerradoException $e) {
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(2, $e->periodMonth);
        }
    }

    public function test_updating_retention_cannot_escape_declared_original_period(): void
    {
        // Attack vector: retención en período declarado, intento moverla a
        // período abierto para poder editarla después.
        $retention = $this->makeRetention([
            'period_year' => 2026,
            'period_month' => 3,
        ]);
        $this->closePeriod(2026, 3);

        try {
            $retention->update([
                'period_year' => 2026,
                'period_month' => 4, // abierto
            ]);
            $this->fail('Se esperaba bloqueo por período ORIGINAL cerrado.');
        } catch (PeriodoFiscalCerradoException $e) {
            // El mensaje identifica el período original (marzo).
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(3, $e->periodMonth);
        }
    }

    // ─── deleting ────────────────────────────────────────────────────

    public function test_deleting_retention_in_open_period_succeeds(): void
    {
        $retention = $this->makeRetention([
            'period_year' => 2026,
            'period_month' => 3,
        ]);

        $retention->delete();

        $this->assertSoftDeleted('isv_retentions_received', ['id' => $retention->id]);
    }

    public function test_deleting_retention_in_declared_period_is_blocked(): void
    {
        $retention = $this->makeRetention([
            'period_year' => 2026,
            'period_month' => 3,
        ]);
        $this->closePeriod(2026, 3);

        try {
            $retention->delete();
            $this->fail('Se esperaba PeriodoFiscalCerradoException.');
        } catch (PeriodoFiscalCerradoException $e) {
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(3, $e->periodMonth);
        }

        // La retención sigue activa (ni borrada física ni lógicamente).
        $this->assertNull($retention->fresh()->deleted_at);
    }

    // ─── Escape hatch: reapertura ──────────────────────────────────

    public function test_updating_retention_after_period_reopen_is_allowed(): void
    {
        $retention = $this->makeRetention([
            'period_year' => 2026,
            'period_month' => 3,
        ]);

        $user = User::factory()->create();
        $period = FiscalPeriod::factory()
            ->forMonth(2026, 3)
            ->declared($user)
            ->create();

        // Antes de reabrir: bloqueado.
        try {
            $retention->update(['notes' => 'Primer intento']);
            $this->fail('Debió bloquearse antes del reopen.');
        } catch (PeriodoFiscalCerradoException) {
            // ok, esperado.
        }

        $period->update([
            'reopened_at' => now(),
            'reopened_by' => $user->id,
            'reopen_reason' => 'Rectificativa',
        ]);

        // Post-reopen: permitido.
        $retention->update(['notes' => 'Post-reopen']);
        $this->assertEquals('Post-reopen', $retention->fresh()->notes);
    }
}

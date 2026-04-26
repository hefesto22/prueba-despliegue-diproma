<?php

namespace Tests\Feature\Services\Alerts;

use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Services\Alerts\CaiRangeExhaustionChecker;
use App\Services\Alerts\Enums\CaiAlertSeverity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cubre CaiRangeExhaustionChecker:
 *   - Solo alerta CAIs activos que cumplen isNearExhaustion() (helper del
 *     modelo que ya combina umbrales % y absoluto de CompanySetting).
 *   - Severidad por presencia de sucesor: Urgent con sucesor, Critical sin.
 *   - No alerta sobre CAIs ya totalmente agotados (remaining = 0).
 */
class CaiRangeExhaustionCheckerTest extends TestCase
{
    use RefreshDatabase;

    private CompanySetting $company;

    private Establishment $matriz;

    private CaiRangeExhaustionChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');

        $this->company = CompanySetting::factory()->create(['rtn' => '08011999123456']);
        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        CarbonImmutable::setTestNow('2026-04-18');

        $this->checker = app(CaiRangeExhaustionChecker::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function setUmbrales(int $absoluto, float $porcentaje): void
    {
        $this->company->update([
            'cai_exhaustion_warning_absolute' => $absoluto,
            'cai_exhaustion_warning_percentage' => $porcentaje,
        ]);
        // Refrescar cache con el company ya actualizado. NO usar Cache::forget
        // porque CompanySetting::current() usa firstOrCreate(['id' => 1]) y
        // si el factory creó la company con id != 1 se generaría un row fantasma.
        Cache::put('company_settings', $this->company->fresh(), 60 * 60 * 24);
    }

    public function test_no_dispara_cuando_rango_esta_lejos_de_agotarse(): void
    {
        $this->setUmbrales(absoluto: 50, porcentaje: 10.0);

        CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 1000,
            'current_number' => 500, // remaining 500 → 50%, lejos del umbral.
        ]);

        $this->assertCount(0, $this->checker->check());
    }

    public function test_dispara_urgent_cuando_cerca_de_agotarse_con_sucesor(): void
    {
        $this->setUmbrales(absoluto: 50, porcentaje: 1.0); // solo dispara por absoluto

        $activo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 1000,
            'current_number' => 960, // remaining 40 → bajo el umbral 50.
        ]);

        CaiRange::factory()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1001,
            'range_end' => 2000,
            'is_active' => false,
            'expiration_date' => now()->addMonths(12)->toDateString(),
        ]);

        $alerts = $this->checker->check();

        $this->assertCount(1, $alerts);
        $this->assertSame($activo->id, $alerts->first()->cai->id);
        $this->assertSame(CaiAlertSeverity::Urgent, $alerts->first()->severity);
        $this->assertTrue($alerts->first()->hasSuccessor);
        $this->assertSame(40, $alerts->first()->remaining);
    }

    public function test_dispara_critical_cuando_cerca_de_agotarse_sin_sucesor(): void
    {
        $this->setUmbrales(absoluto: 50, porcentaje: 1.0);

        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 1000,
            'current_number' => 960,
        ]);

        $alerts = $this->checker->check();

        $this->assertCount(1, $alerts);
        $this->assertSame(CaiAlertSeverity::Critical, $alerts->first()->severity);
        $this->assertFalse($alerts->first()->hasSuccessor);
    }

    public function test_no_alerta_sobre_caios_totalmente_agotados(): void
    {
        $this->setUmbrales(absoluto: 50, porcentaje: 10.0);

        // Agotado (remaining = 0) → isNearExhaustion retorna false por diseño.
        CaiRange::factory()->active()->exhausted()->create([
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $this->assertCount(0, $this->checker->check());
    }

    public function test_ignora_caios_inactivos(): void
    {
        $this->setUmbrales(absoluto: 50, porcentaje: 1.0);

        CaiRange::factory()->create([
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 1000,
            'current_number' => 960,
            'is_active' => false,
        ]);

        $this->assertCount(0, $this->checker->check());
    }

    public function test_reporta_remaining_y_percentage_correctos(): void
    {
        $this->setUmbrales(absoluto: 1, porcentaje: 10.0); // dispara por porcentaje

        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 1000,
            'current_number' => 920, // remaining 80 → 8%
        ]);

        $alert = $this->checker->check()->first();

        $this->assertSame(80, $alert->remaining);
        $this->assertEqualsWithDelta(8.0, $alert->remainingPercentage, 0.1);
    }
}

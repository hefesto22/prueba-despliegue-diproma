<?php

namespace Tests\Feature\Models;

use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cubre `CaiRange::isNearExhaustion()` después del refactor que elimina
 * el umbral hardcoded de 15 y lee los umbrales configurables desde
 * `CompanySetting`.
 *
 * Reglas:
 *   - Dispara cuando `remaining` <= umbral absoluto, O
 *   - Dispara cuando `remaining_percentage` <= umbral porcentual
 *   - No dispara si ya está agotado (otro helper cubre ese caso)
 *   - Usa defaults razonables si CompanySetting no tiene los campos
 */
class CaiRangeNearExhaustionTest extends TestCase
{
    use RefreshDatabase;

    private CompanySetting $company;

    private Establishment $matriz;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');

        $this->company = CompanySetting::factory()->create(['rtn' => '08011999123456']);
        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()->for($this->company, 'companySetting')->main()->create();
    }

    private function setUmbrales(?int $absoluto, ?float $porcentaje): void
    {
        $this->company->update([
            'cai_exhaustion_warning_absolute' => $absoluto,
            'cai_exhaustion_warning_percentage' => $porcentaje,
        ]);
        Cache::forget('company_settings');
        // El cache se calienta en próxima llamada a current() — los getters
        // del modelo lo invocarán y obtendrán los valores frescos.
    }

    public function test_dispara_cuando_remanente_baja_del_umbral_absoluto(): void
    {
        $this->setUmbrales(absoluto: 50, porcentaje: 1.0); // 1% es muy bajo, no debería disparar por porcentaje

        $cai = CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 1000,
            'current_number' => 960, // remaining = 40 (bajo el umbral 50)
        ]);

        $this->assertTrue(
            $cai->isNearExhaustion(),
            'Debe disparar cuando quedan 40 facturas y el umbral absoluto es 50'
        );
    }

    public function test_dispara_cuando_remanente_baja_del_umbral_porcentual(): void
    {
        $this->setUmbrales(absoluto: 1, porcentaje: 10.0); // absoluto muy bajo, dispara solo por porcentaje

        $cai = CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 1000,
            'current_number' => 920, // remaining = 80 → 8% (bajo el umbral 10%)
        ]);

        $this->assertTrue(
            $cai->isNearExhaustion(),
            'Debe disparar cuando queda 8% del rango y el umbral porcentual es 10%'
        );
    }

    public function test_no_dispara_cuando_no_se_cumple_ningun_umbral(): void
    {
        $this->setUmbrales(absoluto: 50, porcentaje: 10.0);

        $cai = CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 1000,
            'current_number' => 500, // remaining = 500 → 50% (lejos de cualquier umbral)
        ]);

        $this->assertFalse($cai->isNearExhaustion());
    }

    public function test_no_dispara_cuando_ya_esta_agotado(): void
    {
        $this->setUmbrales(absoluto: 50, porcentaje: 10.0);

        $cai = CaiRange::factory()->active()->exhausted()->create([
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $this->assertFalse(
            $cai->isNearExhaustion(),
            'Cuando está agotado, isNearExhaustion debe retornar false (otro helper cubre ese caso)'
        );
    }

    public function test_lee_umbrales_configurados_no_el_15_hardcoded(): void
    {
        // Umbral absoluto explícito a 200 — el viejo hardcoded 15 daría false
        // para remaining = 100, pero con umbral 200 debe dar true.
        $this->setUmbrales(absoluto: 200, porcentaje: 1.0);

        $cai = CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 1000,
            'current_number' => 900, // remaining = 100
        ]);

        $this->assertTrue(
            $cai->isNearExhaustion(),
            'Con umbral 200 y remaining 100 debe disparar — el 15 hardcoded ya no aplica'
        );
    }

    public function test_usa_defaults_cuando_company_settings_tiene_los_campos_nulos(): void
    {
        // Forzar nulls — el getter del modelo debe usar los defaults
        // (DEFAULT_CAI_EXHAUSTION_WARNING_ABSOLUTE = 100, PERCENTAGE = 10.00).
        $this->setUmbrales(absoluto: null, porcentaje: null);

        $cai = CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 10000,
            'current_number' => 9920, // remaining = 80 (bajo default 100)
        ]);

        $this->assertTrue(
            $cai->isNearExhaustion(),
            'Sin configuración, debe usar el default absoluto 100'
        );
    }
}

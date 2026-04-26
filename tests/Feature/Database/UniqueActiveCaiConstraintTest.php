<?php

namespace Tests\Feature\Database;

use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Verifica la constraint UNIQUE a nivel de base de datos sobre la columna
 * generada `active_lookup` de `cai_ranges`.
 *
 * Es la red de seguridad más importante del módulo CAI: si por cualquier
 * motivo (bug, race condition, importación manual) se intenta tener dos
 * CAIs simultáneamente activos para el mismo (document_type, establishment_id),
 * MySQL debe rechazar la operación con QueryException — antes de que el
 * sistema produzca correlativos inconsistentes y facturas con números
 * duplicados ante el SAR.
 *
 * Estos tests usan inserciones directas (DB::table o save() con bypass de
 * activate()) para validar que la constraint funciona INDEPENDIENTEMENTE
 * de la lógica de PHP — porque ese es justamente su propósito.
 */
class UniqueActiveCaiConstraintTest extends TestCase
{
    use RefreshDatabase;

    private Establishment $matriz;

    private Establishment $sucursal;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');

        $company = CompanySetting::factory()->create(['rtn' => '08011999123456']);
        Cache::put('company_settings', $company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()->for($company, 'companySetting')->main()->create();
        $this->sucursal = Establishment::factory()
            ->for($company, 'companySetting')
            ->create(['code' => '002', 'is_main' => false]);
    }

    public function test_dos_activos_mismo_doc_y_misma_sucursal_lanza_query_exception(): void
    {
        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $this->expectException(QueryException::class);

        // Bypass de activate(): intento crear directamente otro activo del
        // mismo alcance. La constraint DB debe rechazarlo.
        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 501,
            'range_end' => 1000,
        ]);
    }

    public function test_dos_activos_mismo_doc_distinta_sucursal_es_permitido(): void
    {
        $matrizCai = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $sucursalCai = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->sucursal->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        // Ambos deben poder coexistir activos: arquitectura multi-sucursal
        // requiere un CAI activo por sucursal y por tipo de documento.
        $this->assertTrue($matrizCai->fresh()->is_active);
        $this->assertTrue($sucursalCai->fresh()->is_active);
    }

    public function test_dos_activos_distinto_doc_misma_sucursal_es_permitido(): void
    {
        $facturaCai = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $notaCreditoCai = CaiRange::factory()->active()->create([
            'document_type' => '04',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $this->assertTrue($facturaCai->fresh()->is_active);
        $this->assertTrue($notaCreditoCai->fresh()->is_active);
    }

    public function test_dos_centralizados_activos_mismo_doc_lanza_query_exception(): void
    {
        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => null,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $this->expectException(QueryException::class);

        // COALESCE(establishment_id, 0) en la columna generada hace que dos
        // centralizados del mismo doc también colisionen — correcto, porque
        // un centralizado es un único punto de emisión nacional.
        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => null,
            'range_start' => 501,
            'range_end' => 1000,
        ]);
    }

    public function test_un_activo_y_uno_inactivo_no_colisionan(): void
    {
        $activo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        // Pre-registro del siguiente CAI (is_active = false) — caso central
        // del flujo de pre-registro. No debe colisionar con el activo.
        $preRegistrado = CaiRange::factory()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 501,
            'range_end' => 1000,
            'is_active' => false,
        ]);

        $this->assertTrue($activo->fresh()->is_active);
        $this->assertFalse($preRegistrado->fresh()->is_active);
    }
}

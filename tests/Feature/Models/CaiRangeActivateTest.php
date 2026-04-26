<?php

namespace Tests\Feature\Models;

use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cubre el comportamiento de `CaiRange::activate()` después del refactor
 * que respeta `establishment_id`.
 *
 * Antes del refactor, `activate()` desactivaba TODOS los activos del mismo
 * `document_type` ignorando la sucursal. Eso violaba la arquitectura
 * multi-sucursal F6a: activar el CAI de la matriz desactivaba el de
 * cualquier otra sucursal del mismo tipo de documento.
 *
 * Estos tests validan que la activación respete el alcance correcto:
 *   - Mismo (document_type, establishment_id) → desactiva el conflicto
 *   - Distinto en cualquiera de las dos dimensiones → no toca
 *   - Centralizados (establishment_id null) → solo colisionan entre sí
 */
class CaiRangeActivateTest extends TestCase
{
    use RefreshDatabase;

    private CompanySetting $company;

    private Establishment $matriz;

    private Establishment $sucursal;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');

        $this->company = CompanySetting::factory()->create([
            'rtn' => '08011999123456',
        ]);

        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        $this->sucursal = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create(['code' => '002', 'is_main' => false]);
    }

    public function test_activate_desactiva_otro_activo_del_mismo_doc_y_misma_sucursal(): void
    {
        $viejo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $nuevo = CaiRange::factory()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 501,
            'range_end' => 1000,
            'is_active' => false,
        ]);

        $nuevo->activate();

        $this->assertFalse($viejo->fresh()->is_active, 'El CAI viejo de la misma sucursal y mismo doc debe desactivarse');
        $this->assertTrue($nuevo->fresh()->is_active, 'El nuevo CAI debe quedar activo');
    }

    public function test_activate_no_desactiva_activo_de_otra_sucursal_mismo_doc(): void
    {
        $caiMatriz = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $caiSucursal = CaiRange::factory()->create([
            'document_type' => '01',
            'establishment_id' => $this->sucursal->id,
            'range_start' => 1,
            'range_end' => 500,
            'is_active' => false,
        ]);

        $caiSucursal->activate();

        $this->assertTrue(
            $caiMatriz->fresh()->is_active,
            'El CAI activo de matriz NO debe desactivarse al activar uno de otra sucursal — viola arquitectura F6a'
        );
        $this->assertTrue($caiSucursal->fresh()->is_active);
    }

    public function test_activate_no_desactiva_activo_de_otro_documento_misma_sucursal(): void
    {
        $caiFactura = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $caiNotaCredito = CaiRange::factory()->create([
            'document_type' => '04',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
            'is_active' => false,
        ]);

        $caiNotaCredito->activate();

        $this->assertTrue(
            $caiFactura->fresh()->is_active,
            'CAI de Factura (01) no debe desactivarse al activar uno de Nota de Crédito (04)'
        );
        $this->assertTrue($caiNotaCredito->fresh()->is_active);
    }

    public function test_activate_centralizado_desactiva_otro_centralizado_no_a_los_de_sucursal(): void
    {
        $caiCentralizadoViejo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => null,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $caiSucursalActivo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->sucursal->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $caiCentralizadoNuevo = CaiRange::factory()->create([
            'document_type' => '01',
            'establishment_id' => null,
            'range_start' => 501,
            'range_end' => 1000,
            'is_active' => false,
        ]);

        $caiCentralizadoNuevo->activate();

        $this->assertFalse(
            $caiCentralizadoViejo->fresh()->is_active,
            'El centralizado viejo debe desactivarse'
        );
        $this->assertTrue(
            $caiSucursalActivo->fresh()->is_active,
            'El CAI de sucursal NO debe verse afectado por activar un centralizado'
        );
        $this->assertTrue($caiCentralizadoNuevo->fresh()->is_active);
    }
}

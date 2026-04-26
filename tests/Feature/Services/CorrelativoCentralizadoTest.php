<?php

namespace Tests\Feature\Services;

use App\Enums\DocumentType;
use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Services\Invoicing\Exceptions\CaiVencidoException;
use App\Services\Invoicing\Exceptions\NoHayCaiActivoException;
use App\Services\Invoicing\Exceptions\RangoCaiAgotadoException;
use App\Services\Invoicing\Exceptions\TransaccionRequeridaException;
use App\Services\Invoicing\Resolvers\CorrelativoCentralizado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CorrelativoCentralizadoTest extends TestCase
{
    use RefreshDatabase;

    private CorrelativoCentralizado $resolver;
    private CompanySetting $company;
    private Establishment $mainEstablishment;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('company_settings');
        $this->resolver = new CorrelativoCentralizado();
        $this->company = CompanySetting::factory()->create();
        $this->mainEstablishment = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();
    }

    public function test_retorna_siguiente_numero_con_snapshot_completo(): void
    {
        $cai = CaiRange::factory()->active()->create([
            'prefix' => '001-001-01',
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 0,
        ]);

        $resuelto = DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura));

        $this->assertEquals('001-001-01-00000001', $resuelto->documentNumber);
        $this->assertEquals($cai->cai, $resuelto->cai);
        $this->assertEquals($cai->id, $resuelto->caiRangeId);
        $this->assertEquals($this->mainEstablishment->id, $resuelto->establishmentId);
        $this->assertEquals($this->mainEstablishment->emission_point, $resuelto->emissionPoint);
    }

    public function test_avanza_correlativo_de_manera_secuencial(): void
    {
        CaiRange::factory()->active()->create([
            'prefix' => '001-001-01',
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 5,
        ]);

        $primero = DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura));
        $segundo = DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura));

        $this->assertEquals('001-001-01-00000006', $primero->documentNumber);
        $this->assertEquals('001-001-01-00000007', $segundo->documentNumber);
    }

    public function test_ignora_establishment_del_cai_en_modo_centralizado(): void
    {
        // CAI vinculado a otro establishment (sucursal B) — en modo centralizado debe servir igual
        $sucursalB = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create(['code' => '002', 'emission_point' => '001']);

        $cai = CaiRange::factory()->active()->create([
            'establishment_id' => $sucursalB->id,
            'prefix' => '002-001-01',
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 0,
        ]);

        // Aunque el llamador no pase establishment_id, el CAI tiene uno vinculado → se usa ese
        $resuelto = DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura));

        $this->assertEquals($cai->id, $resuelto->caiRangeId);
        $this->assertEquals($sucursalB->id, $resuelto->establishmentId);
        $this->assertEquals('001', $resuelto->emissionPoint);
    }

    public function test_usa_establishment_fallback_cuando_cai_no_tiene_vinculo(): void
    {
        CaiRange::factory()->active()->create([
            'establishment_id' => null,
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 0,
        ]);

        $sucursalB = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create(['code' => '002', 'emission_point' => '001']);

        $resuelto = DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura, $sucursalB->id));

        $this->assertEquals($sucursalB->id, $resuelto->establishmentId);
    }

    public function test_cae_a_matriz_cuando_cai_sin_vinculo_y_sin_fallback(): void
    {
        CaiRange::factory()->active()->create([
            'establishment_id' => null,
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 0,
        ]);

        $resuelto = DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura));

        $this->assertEquals($this->mainEstablishment->id, $resuelto->establishmentId);
    }

    public function test_lanza_excepcion_cuando_no_hay_cai_activo(): void
    {
        // No se crea ningún CAI
        $this->expectException(NoHayCaiActivoException::class);

        DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura));
    }

    public function test_lanza_excepcion_cuando_cai_esta_vencido(): void
    {
        CaiRange::factory()->active()->expired()->create([
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 0,
        ]);

        $this->expectException(CaiVencidoException::class);

        DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura));
    }

    public function test_lanza_excepcion_cuando_rango_esta_agotado(): void
    {
        CaiRange::factory()->active()->create([
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 100,
        ]);

        $this->expectException(RangoCaiAgotadoException::class);

        DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura));
    }

    public function test_lanza_excepcion_si_se_llama_fuera_de_transaccion(): void
    {
        // RefreshDatabase envuelve cada test en transacción (DB::transactionLevel() >= 1).
        // Para probar el guard usamos rollBack() — NO commit() — porque commit persiste
        // los datos del setUp en BD y contamina los tests siguientes del suite.
        // El resolver lanza TransaccionRequeridaException antes de cualquier query,
        // así que no necesitamos crear un CaiRange para probar el guard.
        DB::rollBack(); // descarta datos de setUp y deja transactionLevel = 0

        try {
            $this->resolver->siguiente(DocumentType::Factura);
            $this->fail('Se esperaba TransaccionRequeridaException');
        } catch (TransaccionRequeridaException) {
            $this->assertTrue(true); // excepción correcta
        } finally {
            DB::beginTransaction(); // reabrir (vacía) para que RefreshDatabase rollback limpio
        }
    }

    public function test_respeta_document_type_filtrando_cai_correcto(): void
    {
        // CAI tipo 01 (factura)
        $caiFactura = CaiRange::factory()->active()->create([
            'document_type'  => DocumentType::Factura->value,
            'prefix'         => '001-001-01',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 10,
        ]);

        // CAI tipo 03 (nota de crédito) — no debe usarse al pedir tipo factura.
        // Este es el caso defensivo del bug que corregimos: el enum mapea SAR
        // correctamente (NC='03') y los dos CAI conviven sin canibalizarse.
        CaiRange::factory()->active()->create([
            'document_type'  => DocumentType::NotaCredito->value,
            'prefix'         => '001-001-03',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 50,
        ]);

        $resuelto = DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura));

        $this->assertEquals($caiFactura->id, $resuelto->caiRangeId);
        $this->assertEquals('001-001-01-00000011', $resuelto->documentNumber);
    }

    public function test_resuelve_correlativo_de_nota_de_credito_separado_de_factura(): void
    {
        // Dos CAI simultáneos por empresa (Factura + NotaCredito) — SAR permite
        // y requiere un CAI por tipo de documento. El resolver debe devolver el
        // correcto según el DocumentType recibido.
        CaiRange::factory()->active()->create([
            'document_type'  => DocumentType::Factura->value,
            'prefix'         => '001-001-01',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 0,
        ]);

        $caiNc = CaiRange::factory()->active()->create([
            'document_type'  => DocumentType::NotaCredito->value,
            'prefix'         => '001-001-03',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 0,
        ]);

        $resuelto = DB::transaction(fn () => $this->resolver->siguiente(DocumentType::NotaCredito));

        $this->assertEquals($caiNc->id, $resuelto->caiRangeId);
        $this->assertEquals('001-001-03-00000001', $resuelto->documentNumber);
    }
}

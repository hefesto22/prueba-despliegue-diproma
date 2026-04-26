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
use App\Services\Invoicing\Resolvers\CorrelativoPorSucursal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class CorrelativoPorSucursalTest extends TestCase
{
    use RefreshDatabase;

    private CorrelativoPorSucursal $resolver;
    private CompanySetting $company;
    private Establishment $matriz;
    private Establishment $sucursalB;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('company_settings');
        $this->resolver = new CorrelativoPorSucursal();
        $this->company = CompanySetting::factory()->create();
        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();
        $this->sucursalB = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create(['code' => '002', 'emission_point' => '001']);
    }

    public function test_requiere_establishment_id_obligatorio(): void
    {
        CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('establishment_id es obligatorio');

        DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura, null));
    }

    public function test_retorna_numero_del_cai_de_la_sucursal_correcta(): void
    {
        $caiMatriz = CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'prefix' => '001-001-01',
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 10,
        ]);

        $caiSucursalB = CaiRange::factory()->active()->create([
            'establishment_id' => $this->sucursalB->id,
            'prefix' => '002-001-01',
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 50,
        ]);

        $resueltoMatriz = DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura, $this->matriz->id));
        $resueltoSucursal = DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura, $this->sucursalB->id));

        $this->assertEquals($caiMatriz->id, $resueltoMatriz->caiRangeId);
        $this->assertEquals('001-001-01-00000011', $resueltoMatriz->documentNumber);
        $this->assertEquals($this->matriz->id, $resueltoMatriz->establishmentId);

        $this->assertEquals($caiSucursalB->id, $resueltoSucursal->caiRangeId);
        $this->assertEquals('002-001-01-00000051', $resueltoSucursal->documentNumber);
        $this->assertEquals($this->sucursalB->id, $resueltoSucursal->establishmentId);
    }

    public function test_aisla_numeracion_entre_sucursales(): void
    {
        CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'prefix' => '001-001-01',
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 0,
        ]);

        CaiRange::factory()->active()->create([
            'establishment_id' => $this->sucursalB->id,
            'prefix' => '002-001-01',
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 0,
        ]);

        // Emitir 3 facturas en matriz
        $m1 = DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura, $this->matriz->id));
        $m2 = DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura, $this->matriz->id));
        $m3 = DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura, $this->matriz->id));

        // Emitir 1 factura en sucursal B — NO debe saltarse a 4
        $s1 = DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura, $this->sucursalB->id));

        $this->assertEquals('001-001-01-00000001', $m1->documentNumber);
        $this->assertEquals('001-001-01-00000002', $m2->documentNumber);
        $this->assertEquals('001-001-01-00000003', $m3->documentNumber);
        $this->assertEquals('002-001-01-00000001', $s1->documentNumber);
    }

    public function test_lanza_excepcion_cuando_sucursal_no_tiene_cai_propio(): void
    {
        // CAI solo para matriz; la sucursal B no tiene CAI asignado
        CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 0,
        ]);

        $this->expectException(NoHayCaiActivoException::class);

        DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura, $this->sucursalB->id));
    }

    public function test_lanza_excepcion_cuando_cai_de_sucursal_esta_vencido(): void
    {
        CaiRange::factory()->active()->expired()->create([
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 0,
        ]);

        $this->expectException(CaiVencidoException::class);

        DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura, $this->matriz->id));
    }

    public function test_lanza_excepcion_cuando_rango_de_sucursal_esta_agotado(): void
    {
        CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 100,
        ]);

        $this->expectException(RangoCaiAgotadoException::class);

        DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura, $this->matriz->id));
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
            $this->resolver->siguiente(DocumentType::Factura, $this->matriz->id);
            $this->fail('Se esperaba TransaccionRequeridaException');
        } catch (TransaccionRequeridaException) {
            $this->assertTrue(true);
        } finally {
            DB::beginTransaction(); // reabrir (vacía) para RefreshDatabase rollback limpio
        }
    }

    public function test_no_usa_cai_de_otra_sucursal_aunque_este_activo(): void
    {
        // Matriz tiene CAI con mucho rango disponible
        CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'prefix' => '001-001-01',
            'range_start' => 1,
            'range_end' => 1000,
            'current_number' => 0,
        ]);

        // Sucursal B NO tiene CAI — aunque la matriz tenga uno disponible, debe fallar
        $this->expectException(NoHayCaiActivoException::class);

        DB::transaction(fn () => $this->resolver->siguiente(DocumentType::Factura, $this->sucursalB->id));
    }
}

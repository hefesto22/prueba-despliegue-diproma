<?php

namespace Tests\Feature\Services\Cai;

use App\Enums\DocumentType;
use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Services\Cai\CaiAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests del service que decide la VISIBILIDAD del botón "Emitir NC" en
 * Filament. La lógica de selección de CAI debe ser idéntica a la de los
 * resolvers ({@see CorrelativoCentralizado} / {@see CorrelativoPorSucursal})
 * — si la UI promete algo que el resolver rechaza, el operador hace click
 * y se topa con NoHayCaiActivoException. Los tests garantizan ese contrato.
 *
 * Cubre además el memo interno: requisito de performance porque Filament
 * resuelve el closure `visible()` por cada fila del listado y sin memo
 * compartido un render de 50 facturas dispararía 50 queries idénticas.
 */
class CaiAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private CaiAvailabilityService $service;
    private CompanySetting $company;
    private Establishment $matriz;
    private Establishment $sucursalB;

    protected function setUp(): void
    {
        parent::setUp();

        // Mismo housekeeping que CorrelativoCentralizadoTest: el cache del
        // CompanySetting puede contaminar entre tests si el SUT lo lee.
        Cache::forget('company_settings');

        $this->service = new CaiAvailabilityService();
        $this->company = CompanySetting::factory()->create();
        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();
        $this->sucursalB = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create(['code' => '002', 'emission_point' => '001']);

        // Default modo centralizado salvo que el test lo cambie. La config
        // se resetea entre tests por el bootstrap de Laravel.
        config(['invoicing.mode' => 'centralizado']);
    }

    // ─── Modo CENTRALIZADO ──────────────────────────────────────────

    public function test_retorna_false_cuando_no_existe_ningun_cai(): void
    {
        $this->assertFalse(
            $this->service->hasActiveCaiFor(DocumentType::NotaCredito)
        );
    }

    public function test_centralizado_retorna_true_con_cai_activo_del_tipo_correcto(): void
    {
        CaiRange::factory()->active()->create([
            'document_type'  => DocumentType::NotaCredito->value,
            'prefix'         => '001-001-03',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 0,
        ]);

        $this->assertTrue(
            $this->service->hasActiveCaiFor(DocumentType::NotaCredito)
        );
    }

    public function test_centralizado_ignora_establishment_id_pasado(): void
    {
        // CAI vinculado a sucursalB, pregunta por matriz → en centralizado
        // el alcance es global por empresa, debe servir igual.
        CaiRange::factory()->active()->create([
            'establishment_id' => $this->sucursalB->id,
            'document_type'    => DocumentType::NotaCredito->value,
            'prefix'           => '002-001-03',
            'range_start'      => 1,
            'range_end'        => 100,
            'current_number'   => 0,
        ]);

        $this->assertTrue(
            $this->service->hasActiveCaiFor(DocumentType::NotaCredito, $this->matriz->id)
        );
    }

    public function test_centralizado_no_cuenta_cai_de_otro_tipo_de_documento(): void
    {
        // Solo CAI de Factura ('01'), pregunta por NC ('03') → false.
        // Defensa contra el bug clásico: el resolver tampoco usaría este CAI
        // para emitir NC, así que la UI no debe ofrecer el botón.
        CaiRange::factory()->active()->create([
            'document_type'  => DocumentType::Factura->value,
            'prefix'         => '001-001-01',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 0,
        ]);

        $this->assertFalse(
            $this->service->hasActiveCaiFor(DocumentType::NotaCredito)
        );
    }

    public function test_no_cuenta_cai_inactivo(): void
    {
        // is_active=false (default del factory sin ->active()).
        CaiRange::factory()->create([
            'document_type'  => DocumentType::NotaCredito->value,
            'prefix'         => '001-001-03',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 0,
            'is_active'      => false,
        ]);

        $this->assertFalse(
            $this->service->hasActiveCaiFor(DocumentType::NotaCredito)
        );
    }

    public function test_no_cuenta_cai_vencido(): void
    {
        CaiRange::factory()->active()->expired()->create([
            'document_type'  => DocumentType::NotaCredito->value,
            'prefix'         => '001-001-03',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 0,
        ]);

        $this->assertFalse(
            $this->service->hasActiveCaiFor(DocumentType::NotaCredito)
        );
    }

    public function test_no_cuenta_cai_agotado(): void
    {
        // current_number = range_end → ya emitió la última. El resolver
        // rechazaría con RangoCaiAgotadoException, así que el botón no
        // debe verse.
        CaiRange::factory()->active()->create([
            'document_type'  => DocumentType::NotaCredito->value,
            'prefix'         => '001-001-03',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 100,
        ]);

        $this->assertFalse(
            $this->service->hasActiveCaiFor(DocumentType::NotaCredito)
        );
    }

    // ─── Modo POR_SUCURSAL ──────────────────────────────────────────

    public function test_por_sucursal_requiere_cai_de_la_misma_sucursal(): void
    {
        config(['invoicing.mode' => 'por_sucursal']);

        CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'document_type'    => DocumentType::NotaCredito->value,
            'prefix'           => '001-001-03',
            'range_start'      => 1,
            'range_end'        => 100,
            'current_number'   => 0,
        ]);

        $this->assertTrue(
            $this->service->hasActiveCaiFor(DocumentType::NotaCredito, $this->matriz->id)
        );
    }

    public function test_por_sucursal_no_acepta_cai_de_otra_sucursal(): void
    {
        config(['invoicing.mode' => 'por_sucursal']);

        // CAI vinculado SOLO a sucursalB, pregunta por matriz → false
        CaiRange::factory()->active()->create([
            'establishment_id' => $this->sucursalB->id,
            'document_type'    => DocumentType::NotaCredito->value,
            'prefix'           => '002-001-03',
            'range_start'      => 1,
            'range_end'        => 100,
            'current_number'   => 0,
        ]);

        $this->assertFalse(
            $this->service->hasActiveCaiFor(DocumentType::NotaCredito, $this->matriz->id)
        );
    }

    public function test_por_sucursal_con_establishment_null_retorna_false_sin_query(): void
    {
        config(['invoicing.mode' => 'por_sucursal']);

        // Existe un CAI activo válido — igual debe dar false porque sin
        // establishment no hay alcance donde buscar (mismo contrato que el
        // resolver, que lanza InvalidArgumentException).
        CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'document_type'    => DocumentType::NotaCredito->value,
            'prefix'           => '001-001-03',
            'range_start'      => 1,
            'range_end'        => 100,
            'current_number'   => 0,
        ]);

        // Capturamos las queries para verificar que el short-circuit por
        // establishmentId=null evita pegarle a la DB.
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertFalse(
            $this->service->hasActiveCaiFor(DocumentType::NotaCredito, null)
        );

        $caiQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'cai_ranges'))
            ->count();

        // El short-circuit retorna false ANTES de tocar cai_ranges.
        $this->assertSame(0, $caiQueries, 'No debe consultar cai_ranges cuando establishment es null');

        DB::disableQueryLog();
    }

    // ─── Memo interno ───────────────────────────────────────────────

    public function test_memo_evita_segunda_query_con_misma_clave(): void
    {
        CaiRange::factory()->active()->create([
            'document_type'  => DocumentType::NotaCredito->value,
            'prefix'         => '001-001-03',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 0,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        // Primera llamada: dispara query.
        $this->assertTrue($this->service->hasActiveCaiFor(DocumentType::NotaCredito));

        $primerasQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'cai_ranges'))
            ->count();

        $this->assertSame(1, $primerasQueries, 'La primera llamada debe disparar exactamente 1 query a cai_ranges');

        // Segunda y tercera llamada con MISMOS argumentos: deben leer del memo.
        $this->service->hasActiveCaiFor(DocumentType::NotaCredito);
        $this->service->hasActiveCaiFor(DocumentType::NotaCredito);

        $totalQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'cai_ranges'))
            ->count();

        $this->assertSame(1, $totalQueries, 'Las llamadas subsiguientes con la misma clave NO deben disparar nuevas queries');

        DB::disableQueryLog();
    }

    public function test_centralizado_memoiza_bajo_clave_unica_aunque_cambie_el_establishment(): void
    {
        // En modo centralizado el establishmentId es irrelevante — el memo
        // debe colapsar todas las consultas a una sola entrada `{type}:any`.
        // Sin esto, un listado de 50 facturas con 5 sucursales distintas
        // dispararía 5 queries en vez de 1.
        CaiRange::factory()->active()->create([
            'document_type'  => DocumentType::NotaCredito->value,
            'prefix'         => '001-001-03',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 0,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertTrue($this->service->hasActiveCaiFor(DocumentType::NotaCredito, $this->matriz->id));
        $this->assertTrue($this->service->hasActiveCaiFor(DocumentType::NotaCredito, $this->sucursalB->id));
        $this->assertTrue($this->service->hasActiveCaiFor(DocumentType::NotaCredito, null));

        $caiQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'cai_ranges'))
            ->count();

        $this->assertSame(1, $caiQueries, 'Modo centralizado debe memoizar bajo una sola clave por DocumentType');

        DB::disableQueryLog();
    }

    public function test_por_sucursal_memoiza_por_combinacion_tipo_establishment(): void
    {
        config(['invoicing.mode' => 'por_sucursal']);

        // Solo matriz tiene CAI; sucursalB no.
        CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'document_type'    => DocumentType::NotaCredito->value,
            'prefix'           => '001-001-03',
            'range_start'      => 1,
            'range_end'        => 100,
            'current_number'   => 0,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        // Cada combinación distinta dispara su propia query (1 + 1 = 2);
        // las repetidas leen del memo.
        $this->assertTrue($this->service->hasActiveCaiFor(DocumentType::NotaCredito, $this->matriz->id));
        $this->assertTrue($this->service->hasActiveCaiFor(DocumentType::NotaCredito, $this->matriz->id));
        $this->assertFalse($this->service->hasActiveCaiFor(DocumentType::NotaCredito, $this->sucursalB->id));
        $this->assertFalse($this->service->hasActiveCaiFor(DocumentType::NotaCredito, $this->sucursalB->id));

        $caiQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'cai_ranges'))
            ->count();

        $this->assertSame(2, $caiQueries, 'Modo por_sucursal debe disparar 1 query por combinación distinta');

        DB::disableQueryLog();
    }

    public function test_flush_cache_invalida_el_memo(): void
    {
        CaiRange::factory()->active()->create([
            'document_type'  => DocumentType::NotaCredito->value,
            'prefix'         => '001-001-03',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 0,
        ]);

        // Llenar el memo con un true.
        $this->assertTrue($this->service->hasActiveCaiFor(DocumentType::NotaCredito));

        // Eliminar el CAI por debajo del service — sin flushCache, el memo
        // seguiría devolviendo true.
        CaiRange::query()->delete();

        // Sin invalidar: el memo retorna lo que cacheó (true).
        $this->assertTrue($this->service->hasActiveCaiFor(DocumentType::NotaCredito));

        // Tras flushCache: re-consulta la DB y obtiene false.
        $this->service->flushCache();
        $this->assertFalse($this->service->hasActiveCaiFor(DocumentType::NotaCredito));
    }
}

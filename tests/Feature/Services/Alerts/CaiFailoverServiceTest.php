<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Alerts;

use App\Events\CaiFailoverExecuted;
use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Services\Alerts\CaiFailoverService;
use App\Services\Alerts\Contracts\ResuelveSucesoresDeCai;
use App\Services\Alerts\DTOs\CaiFailoverReport;
use App\Services\Invoicing\Exceptions\CaiSinSucesorException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * Cubre CaiFailoverService::executeFailover():
 *   - reporte vacío cuando no hay CAIs en estado crítico
 *   - promoción exitosa de sucesor para CAI vencido → bucket `activated`
 *   - promoción exitosa para CAI agotado → bucket `activated`
 *   - CAI vencido sin sucesor → bucket `skippedNoSuccessor` con REASON_EXPIRED
 *   - CAI agotado sin sucesor → bucket `skippedNoSuccessor` con REASON_EXHAUSTED
 *   - CAI vencido Y agotado simultáneamente → precedencia de REASON_EXPIRED
 *   - se dispara el evento de dominio CaiFailoverExecuted por cada activación
 *   - un fallo aislado NO detiene la iteración sobre el resto de CAIs
 *   - CAIs inactivos (is_active=false) quedan fuera del select inicial
 *   - alcance centralizado no toma sucesores ligados a sucursales y viceversa
 */
class CaiFailoverServiceTest extends TestCase
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

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        CarbonImmutable::setTestNow('2026-04-18');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function service(): CaiFailoverService
    {
        return app(CaiFailoverService::class);
    }

    public function test_reporte_vacio_cuando_no_hay_cais_criticos(): void
    {
        // Un CAI activo, vigente y con correlativos libres → NO requiere failover.
        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
            'current_number' => 100,
        ]);

        $report = $this->service()->executeFailover();

        $this->assertInstanceOf(CaiFailoverReport::class, $report);
        $this->assertSame(0, $report->totalProcessed());
        $this->assertTrue($report->activated->isEmpty());
        $this->assertTrue($report->skippedNoSuccessor->isEmpty());
        $this->assertTrue($report->errors->isEmpty());
    }

    public function test_cai_vencido_con_sucesor_valido_es_promovido(): void
    {
        $viejo = CaiRange::factory()->active()->expired()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $sucesor = CaiRange::factory()->create([
            'is_active' => false,
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 501,
            'range_end' => 1000,
            'current_number' => 500,
        ]);

        $report = $this->service()->executeFailover();

        $this->assertSame(1, $report->activated->count());
        $this->assertTrue($report->skippedNoSuccessor->isEmpty());
        $this->assertTrue($report->errors->isEmpty());

        $result = $report->activated->first();
        $this->assertSame($viejo->id, $result->oldCai->id);
        $this->assertSame($sucesor->id, $result->newCai->id);
        $this->assertSame(CaiSinSucesorException::REASON_EXPIRED, $result->reason);

        // Efectos en DB: sucesor activo, viejo desactivado.
        $this->assertTrue($sucesor->fresh()->is_active);
        $this->assertFalse($viejo->fresh()->is_active);
    }

    public function test_cai_agotado_con_sucesor_valido_es_promovido(): void
    {
        $viejo = CaiRange::factory()->active()->exhausted()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $sucesor = CaiRange::factory()->create([
            'is_active' => false,
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 501,
            'range_end' => 1000,
            'current_number' => 500,
        ]);

        $report = $this->service()->executeFailover();

        $this->assertSame(1, $report->activated->count());
        $result = $report->activated->first();
        $this->assertSame($viejo->id, $result->oldCai->id);
        $this->assertSame($sucesor->id, $result->newCai->id);
        $this->assertSame(CaiSinSucesorException::REASON_EXHAUSTED, $result->reason);
    }

    public function test_cai_vencido_sin_sucesor_queda_en_bucket_skipped(): void
    {
        $viejo = CaiRange::factory()->active()->expired()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $report = $this->service()->executeFailover();

        $this->assertTrue($report->activated->isEmpty());
        $this->assertSame(1, $report->skippedNoSuccessor->count());
        $this->assertTrue($report->errors->isEmpty());

        $entry = $report->skippedNoSuccessor->first();
        $this->assertSame($viejo->id, $entry['cai']->id);
        $this->assertInstanceOf(CaiSinSucesorException::class, $entry['exception']);
        $this->assertSame(CaiSinSucesorException::REASON_EXPIRED, $entry['exception']->reason);

        // El viejo sigue activo: no hay a quién promover, no se puede dejar
        // al POS sin ningún CAI activo. La alerta crítica va al admin.
        $this->assertTrue($viejo->fresh()->is_active);
    }

    public function test_cai_agotado_sin_sucesor_queda_en_bucket_skipped(): void
    {
        $viejo = CaiRange::factory()->active()->exhausted()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $report = $this->service()->executeFailover();

        $this->assertSame(1, $report->skippedNoSuccessor->count());
        $entry = $report->skippedNoSuccessor->first();
        $this->assertSame($viejo->id, $entry['cai']->id);
        $this->assertSame(CaiSinSucesorException::REASON_EXHAUSTED, $entry['exception']->reason);
    }

    public function test_cai_vencido_y_agotado_usa_razon_expired_como_precedencia(): void
    {
        // Ambas condiciones simultáneas: vencido Y agotado.
        // Por decisión documentada en resolveReason(), gana "vencido".
        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->subDay()->toDateString(), // vencido
            'range_start' => 1,
            'range_end' => 500,
            'current_number' => 500, // agotado
        ]);

        $report = $this->service()->executeFailover();

        $this->assertSame(1, $report->skippedNoSuccessor->count());
        $entry = $report->skippedNoSuccessor->first();
        $this->assertSame(CaiSinSucesorException::REASON_EXPIRED, $entry['exception']->reason);
    }

    public function test_dispara_evento_cuando_hay_activacion_exitosa(): void
    {
        Event::fake([CaiFailoverExecuted::class]);

        CaiRange::factory()->active()->expired()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        CaiRange::factory()->create([
            'is_active' => false,
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 501,
            'range_end' => 1000,
            'current_number' => 500,
        ]);

        $this->service()->executeFailover();

        Event::assertDispatched(CaiFailoverExecuted::class, 1);
    }

    public function test_un_error_inesperado_no_detiene_la_iteracion_sobre_los_demas(): void
    {
        // CAI 1: el resolver va a lanzar una excepción genérica (bug simulado).
        // CAI 2: el resolver va a retornar null correctamente.
        // Esperado: ambos CAIs aparecen en el reporte —
        //   - CAI 1 en bucket `errors` con su Throwable.
        //   - CAI 2 en bucket `skippedNoSuccessor` como caso normal.
        // NO se propaga ninguna excepción al caller.

        $caiProblematico = CaiRange::factory()->active()->expired()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $caiNormal = CaiRange::factory()->active()->expired()->create([
            'document_type' => '03',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        // Fake concreto que implementa el contrato: lanza Throwable genérico
        // para CAI 1, retorna null para CAI 2. No usamos Mockery / createMock
        // porque el resolver concreto es `final` — en lugar de eso dependemos
        // del contrato `ResuelveSucesoresDeCai` (DIP en acción).
        $this->app->bind(ResuelveSucesoresDeCai::class, fn () => new class($caiProblematico->id) implements ResuelveSucesoresDeCai
        {
            public function __construct(private readonly int $failForCaiId) {}

            public function findSuccessorFor(CaiRange $cai): ?CaiRange
            {
                if ($cai->id === $this->failForCaiId) {
                    throw new RuntimeException('Fallo simulado de DB');
                }

                return null;
            }
        });

        $report = $this->service()->executeFailover();

        $this->assertSame(2, $report->totalProcessed());
        $this->assertSame(1, $report->errors->count());
        $this->assertSame(1, $report->skippedNoSuccessor->count());

        $errorEntry = $report->errors->first();
        $this->assertSame($caiProblematico->id, $errorEntry['cai']->id);
        $this->assertInstanceOf(RuntimeException::class, $errorEntry['exception']);

        $skippedEntry = $report->skippedNoSuccessor->first();
        $this->assertSame($caiNormal->id, $skippedEntry['cai']->id);
    }

    public function test_cais_inactivos_quedan_fuera_del_procesamiento(): void
    {
        // Un CAI vencido Y agotado, pero con is_active=false → no se procesa.
        // El failover solo actúa sobre CAIs que están en uso hoy.
        CaiRange::factory()->expired()->exhausted()->create([
            'is_active' => false,
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $report = $this->service()->executeFailover();

        $this->assertSame(0, $report->totalProcessed());
    }

    public function test_alcance_centralizado_no_toma_sucesor_de_sucursal(): void
    {
        // CAI centralizado (establishment_id=null) vencido.
        $viejo = CaiRange::factory()->active()->expired()->create([
            'document_type' => '01',
            'establishment_id' => null, // centralizado
            'range_start' => 1,
            'range_end' => 500,
        ]);

        // Sucesor elegible pero ligado a la matriz (no centralizado) —
        // el resolver debe rechazarlo por mismatch de alcance.
        CaiRange::factory()->create([
            'is_active' => false,
            'document_type' => '01',
            'establishment_id' => $this->matriz->id, // sucursal, no centralizado
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 501,
            'range_end' => 1000,
            'current_number' => 500,
        ]);

        $report = $this->service()->executeFailover();

        // El viejo queda en skippedNoSuccessor porque no hay sucesor compatible.
        $this->assertSame(1, $report->skippedNoSuccessor->count());
        $this->assertSame($viejo->id, $report->skippedNoSuccessor->first()['cai']->id);
    }
}

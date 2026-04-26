<?php

declare(strict_types=1);

namespace Tests\Feature\Listeners;

use App\Events\CaiFailoverExecuted;
use App\Listeners\LogCaiFailoverActivity;
use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Services\Alerts\DTOs\CaiFailoverResult;
use App\Services\Invoicing\Exceptions\CaiSinSucesorException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Cubre LogCaiFailoverActivity:
 *   - persiste entry en activity_log con log_name='cai_failover' y event='failover_executed'
 *   - subject (performedOn) apunta al newCai (el promovido)
 *   - causer null: las activaciones automáticas NO son imputables a un usuario
 *   - properties incluye snapshot completo de oldCai y newCai
 *
 * El listener se invoca directamente (sin dispatcher) para aislar el test de
 * la pipeline de colas — el contrato del listener es "dado este evento,
 * produce esta entrada de auditoría", no "se ejecuta vía Horizon".
 */
class LogCaiFailoverActivityTest extends TestCase
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

    private function buildEvent(string $reason = CaiSinSucesorException::REASON_EXPIRED): CaiFailoverExecuted
    {
        $old = CaiRange::factory()->create([
            'is_active' => false, // ya fue desactivado por el failover
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->subDay()->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
            'current_number' => 250,
        ]);

        $new = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 501,
            'range_end' => 1000,
            'current_number' => 500,
        ]);

        return new CaiFailoverExecuted(new CaiFailoverResult(
            oldCai: $old,
            newCai: $new,
            reason: $reason,
        ));
    }

    public function test_registra_entry_con_log_name_y_event_correctos(): void
    {
        $event = $this->buildEvent();

        (new LogCaiFailoverActivity)->handle($event);

        $activity = Activity::where('log_name', 'cai_failover')->latest('id')->first();

        $this->assertNotNull($activity, 'Se esperaba una entrada en activity_log con log_name=cai_failover');
        $this->assertSame('cai_failover', $activity->log_name);
        $this->assertSame('failover_executed', $activity->event);
        $this->assertNull($activity->causer_id, 'El causer debe ser null: el failover lo dispara el sistema, no un humano');
        $this->assertStringContainsString('Failover automático de CAI', $activity->description);
    }

    public function test_subject_apunta_al_nuevo_cai_promovido(): void
    {
        $event = $this->buildEvent();

        (new LogCaiFailoverActivity)->handle($event);

        $activity = Activity::where('log_name', 'cai_failover')->latest('id')->first();

        $this->assertSame(CaiRange::class, $activity->subject_type);
        $this->assertSame($event->result->newCai->id, $activity->subject_id);
    }

    public function test_properties_contienen_snapshot_completo_de_ambos_cais(): void
    {
        $event = $this->buildEvent(CaiSinSucesorException::REASON_EXHAUSTED);

        (new LogCaiFailoverActivity)->handle($event);

        $activity = Activity::where('log_name', 'cai_failover')->latest('id')->first();

        $properties = $activity->properties->toArray();

        // Metadatos del evento
        $this->assertSame(CaiSinSucesorException::REASON_EXHAUSTED, $properties['reason']);
        $this->assertSame('agotado', $properties['reason_human']);
        $this->assertSame('01', $properties['document_type']);
        $this->assertSame($this->matriz->id, $properties['establishment_id']);

        // Snapshot del CAI viejo (el que se desactivó)
        $old = $event->result->oldCai;
        $this->assertSame($old->id, $properties['old_cai']['id']);
        $this->assertSame($old->cai, $properties['old_cai']['cai']);
        $this->assertSame($old->prefix, $properties['old_cai']['prefix']);
        $this->assertSame($old->range_start, $properties['old_cai']['range_start']);
        $this->assertSame($old->range_end, $properties['old_cai']['range_end']);
        $this->assertSame($old->current_number, $properties['old_cai']['current_number']);
        $this->assertSame($old->expiration_date->toDateString(), $properties['old_cai']['expiration_date']);

        // Snapshot del CAI nuevo (el promovido)
        $new = $event->result->newCai;
        $this->assertSame($new->id, $properties['new_cai']['id']);
        $this->assertSame($new->cai, $properties['new_cai']['cai']);
        $this->assertSame($new->prefix, $properties['new_cai']['prefix']);
        $this->assertSame($new->range_start, $properties['new_cai']['range_start']);
        $this->assertSame($new->range_end, $properties['new_cai']['range_end']);
        $this->assertSame($new->expiration_date->toDateString(), $properties['new_cai']['expiration_date']);
    }
}

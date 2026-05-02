<?php

namespace Tests\Feature\Jobs;

use App\Enums\RepairLogEvent;
use App\Enums\RepairStatus;
use App\Jobs\MarkAbandonedRepairsJob;
use App\Models\DeviceCategory;
use App\Models\Repair;
use App\Models\RepairStatusLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

class MarkAbandonedRepairsJobTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMatriz;

    private DeviceCategory $deviceCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deviceCategory = DeviceCategory::factory()->create();
        config(['repairs.abandonment_days' => 60]);
    }

    private function makeReadyForDelivery(int $daysAgo): Repair
    {
        return Repair::factory()
            ->readyForDelivery()
            ->create([
                'establishment_id' => $this->matriz->id,
                'device_category_id' => $this->deviceCategory->id,
                'completed_at' => now()->subDays($daysAgo),
            ]);
    }

    public function test_marca_como_abandonada_repairs_con_60_dias_o_mas_en_listo_entrega(): void
    {
        $stale = $this->makeReadyForDelivery(daysAgo: 65);
        $atThreshold = $this->makeReadyForDelivery(daysAgo: 60);
        $fresh = $this->makeReadyForDelivery(daysAgo: 30);

        (new MarkAbandonedRepairsJob)->handle();

        $this->assertEquals(RepairStatus::Abandonada, $stale->fresh()->status);
        $this->assertNotNull($stale->fresh()->abandoned_at);

        $this->assertEquals(RepairStatus::Abandonada, $atThreshold->fresh()->status);

        // El reciente sigue intacto
        $this->assertEquals(RepairStatus::ListoEntrega, $fresh->fresh()->status);
        $this->assertNull($fresh->fresh()->abandoned_at);
    }

    public function test_no_toca_repairs_en_otros_estados(): void
    {
        // Una en EnReparación con fecha vieja: NO debe tocarla
        $inRepair = Repair::factory()
            ->inRepair()
            ->create([
                'establishment_id' => $this->matriz->id,
                'device_category_id' => $this->deviceCategory->id,
                'repair_started_at' => now()->subDays(120),
            ]);

        // Una ya entregada hace tiempo: NO debe tocarla
        $delivered = Repair::factory()
            ->delivered()
            ->create([
                'establishment_id' => $this->matriz->id,
                'device_category_id' => $this->deviceCategory->id,
                'delivered_at' => now()->subDays(120),
            ]);

        (new MarkAbandonedRepairsJob)->handle();

        $this->assertEquals(RepairStatus::EnReparacion, $inRepair->fresh()->status);
        $this->assertEquals(RepairStatus::Entregada, $delivered->fresh()->status);
    }

    public function test_registra_evento_status_change_con_metadata(): void
    {
        $repair = $this->makeReadyForDelivery(daysAgo: 75);

        (new MarkAbandonedRepairsJob)->handle();

        $log = RepairStatusLog::where('repair_id', $repair->id)
            ->where('event_type', RepairLogEvent::StatusChange)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(RepairStatus::ListoEntrega, $log->from_status);
        $this->assertEquals(RepairStatus::Abandonada, $log->to_status);
        $this->assertNull($log->changed_by, 'La transición automática no debe atribuirse a ningún usuario.');
        $this->assertEquals('auto_abandoned', $log->metadata['reason']);
        $this->assertEquals(60, $log->metadata['threshold_days']);
    }

    public function test_idempotente_re_corrida_no_afecta_repairs_ya_abandonadas(): void
    {
        $repair = $this->makeReadyForDelivery(daysAgo: 75);

        (new MarkAbandonedRepairsJob)->handle();
        $abandonedAt = $repair->fresh()->abandoned_at;

        // Re-correr el job: la repair ya está Abandonada, no debe procesarse de nuevo.
        (new MarkAbandonedRepairsJob)->handle();

        $this->assertEquals($abandonedAt->toDateTimeString(), $repair->fresh()->abandoned_at->toDateTimeString());
        $this->assertEquals(1, RepairStatusLog::where('repair_id', $repair->id)
            ->where('event_type', RepairLogEvent::StatusChange)
            ->count(), 'Solo debe haber UN log de cambio de estado.');
    }
}

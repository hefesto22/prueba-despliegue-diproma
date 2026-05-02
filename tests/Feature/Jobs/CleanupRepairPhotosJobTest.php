<?php

namespace Tests\Feature\Jobs;

use App\Enums\RepairLogEvent;
use App\Enums\RepairStatus;
use App\Jobs\CleanupRepairPhotosJob;
use App\Models\DeviceCategory;
use App\Models\Repair;
use App\Models\RepairPhoto;
use App\Models\RepairStatusLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

class CleanupRepairPhotosJobTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMatriz;

    private DeviceCategory $deviceCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deviceCategory = DeviceCategory::factory()->create();
        config(['repairs.photo_retention_days' => 7]);

        // Disk fake para no escribir en el storage real durante tests
        Storage::fake('public');
    }

    private function makeRepairWithPhotos(
        RepairStatus $status,
        string $timestampField,
        int $daysAgo,
        int $photoCount = 2,
    ): Repair {
        $factoryState = match ($status) {
            RepairStatus::Entregada => 'delivered',
            RepairStatus::Rechazada => 'rejected',
            default => 'readyForDelivery',
        };

        $factory = Repair::factory()->{$factoryState}();
        $repair = $factory->create([
            'establishment_id' => $this->matriz->id,
            'device_category_id' => $this->deviceCategory->id,
            'status' => $status->value,
            $timestampField => now()->subDays($daysAgo),
        ]);

        // Crear N fotos con archivos físicos en el disk fake
        for ($i = 0; $i < $photoCount; $i++) {
            $path = "repairs/{$repair->id}/photo-{$i}.webp";
            Storage::disk('public')->put($path, 'fake-content');
            RepairPhoto::factory()->create([
                'repair_id' => $repair->id,
                'photo_path' => $path,
            ]);
        }

        return $repair->fresh('photos');
    }

    public function test_borra_fotos_de_repairs_entregadas_hace_7_dias_o_mas(): void
    {
        $stale = $this->makeRepairWithPhotos(RepairStatus::Entregada, 'delivered_at', daysAgo: 10, photoCount: 3);

        (new CleanupRepairPhotosJob)->handle();

        $this->assertEquals(0, $stale->photos()->count(), 'Los registros de fotos deben borrarse.');

        // Los archivos físicos deben haberse borrado del disk
        Storage::disk('public')->assertMissing("repairs/{$stale->id}/photo-0.webp");
        Storage::disk('public')->assertMissing("repairs/{$stale->id}/photo-1.webp");
        Storage::disk('public')->assertMissing("repairs/{$stale->id}/photo-2.webp");
    }

    public function test_no_toca_fotos_de_repairs_recien_entregadas(): void
    {
        $fresh = $this->makeRepairWithPhotos(RepairStatus::Entregada, 'delivered_at', daysAgo: 3);

        (new CleanupRepairPhotosJob)->handle();

        $this->assertEquals(2, $fresh->photos()->count(), 'Las fotos recientes no deben tocarse.');
    }

    public function test_borra_fotos_de_repairs_rechazadas_hace_mas_de_7_dias(): void
    {
        $rejected = $this->makeRepairWithPhotos(RepairStatus::Rechazada, 'rejected_at', daysAgo: 10);

        (new CleanupRepairPhotosJob)->handle();

        $this->assertEquals(0, $rejected->photos()->count());
    }

    public function test_borra_fotos_de_repairs_anuladas_y_abandonadas(): void
    {
        $cancelled = $this->makeRepairWithPhotos(RepairStatus::Anulada, 'cancelled_at', daysAgo: 10);
        $abandoned = $this->makeRepairWithPhotos(RepairStatus::Abandonada, 'abandoned_at', daysAgo: 10);

        (new CleanupRepairPhotosJob)->handle();

        $this->assertEquals(0, $cancelled->photos()->count());
        $this->assertEquals(0, $abandoned->photos()->count());
    }

    public function test_registra_evento_photo_deleted_con_metadata(): void
    {
        $repair = $this->makeRepairWithPhotos(RepairStatus::Entregada, 'delivered_at', daysAgo: 10, photoCount: 3);
        $originalPhotoIds = $repair->photos->pluck('id')->all();

        (new CleanupRepairPhotosJob)->handle();

        $log = RepairStatusLog::where('repair_id', $repair->id)
            ->where('event_type', RepairLogEvent::PhotoDeleted)
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->changed_by);
        $this->assertEquals(7, $log->metadata['retention_days']);
        $this->assertEquals(3, $log->metadata['files_deleted']);
        $this->assertEquals(0, $log->metadata['files_missing']);
        $this->assertEqualsCanonicalizing($originalPhotoIds, $log->metadata['photo_ids']);
    }

    public function test_si_archivo_fisico_no_existe_borra_registro_y_loguea_missing(): void
    {
        $repair = Repair::factory()
            ->delivered()
            ->create([
                'establishment_id' => $this->matriz->id,
                'device_category_id' => $this->deviceCategory->id,
                'delivered_at' => now()->subDays(10),
            ]);

        // Foto solo en BD, archivo físico inexistente
        RepairPhoto::factory()->create([
            'repair_id' => $repair->id,
            'photo_path' => "repairs/{$repair->id}/missing.webp",
        ]);

        (new CleanupRepairPhotosJob)->handle();

        $this->assertEquals(0, $repair->photos()->count(), 'El registro debe borrarse aunque el archivo no existiera.');

        $log = RepairStatusLog::where('repair_id', $repair->id)
            ->where('event_type', RepairLogEvent::PhotoDeleted)
            ->first();
        $this->assertEquals(1, $log->metadata['files_missing']);
        $this->assertEquals(0, $log->metadata['files_deleted']);
    }

    public function test_idempotente_segunda_corrida_no_genera_log_extra(): void
    {
        $repair = $this->makeRepairWithPhotos(RepairStatus::Entregada, 'delivered_at', daysAgo: 10);

        (new CleanupRepairPhotosJob)->handle();
        (new CleanupRepairPhotosJob)->handle();

        $logCount = RepairStatusLog::where('repair_id', $repair->id)
            ->where('event_type', RepairLogEvent::PhotoDeleted)
            ->count();
        $this->assertEquals(1, $logCount, 'La segunda corrida no debe generar log extra (no hay fotos para limpiar).');
    }
}

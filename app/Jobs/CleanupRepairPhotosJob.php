<?php

namespace App\Jobs;

use App\Enums\RepairLogEvent;
use App\Enums\RepairStatus;
use App\Models\Repair;
use App\Models\RepairPhoto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Job programado: borra físicamente las fotos de reparaciones en estados
 * terminales tras un período de retención configurable.
 *
 * Motivación operativa:
 *   El hosting compartido tiene espacio limitado. Las fotos del equipo solo
 *   son útiles durante el ciclo de la reparación (identificar el equipo,
 *   evidencia para reclamos). Una vez entregada la reparación —o después
 *   de cierto tiempo en estado terminal alternativo— las fotos pierden
 *   utilidad práctica y solo consumen espacio.
 *
 *   Política aprobada por Mauricio (2026-05-02):
 *     - 7 días después de Entregada → borrar fotos.
 *     - 7 días después de Rechazada/Anulada/Abandonada → borrar fotos.
 *
 * Implementación:
 *   - Borrado FÍSICO del archivo en disk 'public' (Storage::delete).
 *   - Borrado del registro `repair_photos` en BD (cascade-safe).
 *   - Registro en `repair_status_logs` del evento `PhotoDeleted` con
 *     metadata de los photo_ids para auditoría.
 *
 * Resiliencia:
 *   Cada repair se procesa en su propio try/catch — si falla la limpieza
 *   de uno, el job sigue con los demás. Errores se loguean con repair_id.
 *   Si una foto no existe en disk (ya borrada manualmente), se ignora y
 *   se procede a borrar el registro de BD igual.
 *
 * Idempotencia:
 *   Re-correr el job es inocuo. Las fotos ya borradas no aparecen en la
 *   query (la relación `photos` solo trae lo que existe en BD).
 */
class CleanupRepairPhotosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(): void
    {
        $retentionDays = (int) config('repairs.photo_retention_days', 7);

        $deletedRepairs = $this->cleanup(
            $this->candidatesByDeliveryDate($retentionDays),
            'delivered_at',
            $retentionDays,
        );

        $terminalRepairs = $this->cleanup(
            $this->candidatesByTerminalDate($retentionDays),
            'terminal_at',
            $retentionDays,
        );

        Log::info('CleanupRepairPhotosJob completado', [
            'retention_days' => $retentionDays,
            'cleaned_after_delivery' => $deletedRepairs,
            'cleaned_after_terminal' => $terminalRepairs,
        ]);
    }

    /**
     * Reparaciones Entregadas con fotos pendientes de cleanup.
     */
    private function candidatesByDeliveryDate(int $retentionDays)
    {
        return Repair::query()
            ->where('status', RepairStatus::Entregada->value)
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '<=', now()->subDays($retentionDays))
            ->whereHas('photos')
            ->with('photos:id,repair_id,photo_path')
            ->get();
    }

    /**
     * Reparaciones en estados terminales NO entregadas con fotos pendientes
     * de cleanup. La fecha de referencia es el timestamp de la transición
     * al estado terminal correspondiente (rejected_at / cancelled_at /
     * abandoned_at). Tomamos el más antiguo no nulo.
     */
    private function candidatesByTerminalDate(int $retentionDays)
    {
        $cutoff = now()->subDays($retentionDays);

        return Repair::query()
            ->whereIn('status', [
                RepairStatus::Rechazada->value,
                RepairStatus::Anulada->value,
                RepairStatus::Abandonada->value,
            ])
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($q) use ($cutoff) {
                    $q->whereNotNull('rejected_at')->where('rejected_at', '<=', $cutoff);
                })->orWhere(function ($q) use ($cutoff) {
                    $q->whereNotNull('cancelled_at')->where('cancelled_at', '<=', $cutoff);
                })->orWhere(function ($q) use ($cutoff) {
                    $q->whereNotNull('abandoned_at')->where('abandoned_at', '<=', $cutoff);
                });
            })
            ->whereHas('photos')
            ->with('photos:id,repair_id,photo_path')
            ->get();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Repair>  $repairs
     */
    private function cleanup($repairs, string $reason, int $retentionDays): int
    {
        $count = 0;
        $disk = Storage::disk('public');

        foreach ($repairs as $repair) {
            try {
                $photoIds = [];
                $deletedFiles = 0;
                $missingFiles = 0;

                foreach ($repair->photos as $photo) {
                    if ($photo->photo_path && $disk->exists($photo->photo_path)) {
                        $disk->delete($photo->photo_path);
                        $deletedFiles++;
                    } else {
                        $missingFiles++;
                    }
                    $photoIds[] = $photo->id;
                }

                // Borrado de los registros en BD (un solo DELETE)
                RepairPhoto::whereIn('id', $photoIds)->delete();

                // Auditoría — el StatusLog es WORM
                $repair->statusLogs()->create([
                    'event_type' => RepairLogEvent::PhotoDeleted,
                    'from_status' => null,
                    'to_status' => null,
                    'changed_by' => null, // sistema
                    'metadata' => [
                        'reason' => $reason,
                        'retention_days' => $retentionDays,
                        'photo_ids' => $photoIds,
                        'files_deleted' => $deletedFiles,
                        'files_missing' => $missingFiles,
                    ],
                    'note' => sprintf(
                        'Cleanup automático: %d foto(s) borradas tras %d días.',
                        count($photoIds),
                        $retentionDays,
                    ),
                ]);

                $count++;
            } catch (Throwable $e) {
                Log::warning('CleanupRepairPhotosJob: fallo limpiando repair', [
                    'repair_id' => $repair->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }
}

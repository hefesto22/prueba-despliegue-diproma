<?php

namespace App\Jobs;

use App\Enums\RepairLogEvent;
use App\Enums\RepairStatus;
use App\Models\Repair;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job programado: marca como Abandonadas las reparaciones que llevan demasiado
 * tiempo en estado ListoEntrega sin que el cliente recoja el equipo.
 *
 * Motivación operativa:
 *   El taller no puede tener equipos terminados acumulándose indefinidamente.
 *   Después de N días en ListoEntrega (default 60), si el cliente no recoge,
 *   marcamos la reparación como Abandonada — estado terminal que:
 *     - libera el "slot mental" del cajero (deja de aparecer en filtros activos),
 *     - dispara la limpieza de fotos por F-R6 (CleanupRepairPhotosJob las borra
 *       7 días después de la transición a estado terminal).
 *     - permite eventualmente vender o reciclar el equipo según política interna.
 *
 * Scheduling:
 *   `dailyAt('06:00')->timezone('America/Tegucigalpa')` en routes/console.php.
 *   Una hora antes del horario operativo para que cuando el staff entre, las
 *   reparaciones marcadas ya estén actualizadas y no aparezcan en sus filtros.
 *
 * Idempotencia:
 *   Re-correr el job es inocuo. Las reparaciones ya marcadas Abandonadas se
 *   filtran por estado en la query (`where status = ListoEntrega`), así que
 *   solo procesa las que aún están pendientes. Si se ejecuta dos veces en el
 *   mismo día, la segunda no encuentra nada nuevo.
 *
 * Resiliencia:
 *   Cada repair se procesa en su propio try/catch. Si una falla, el job sigue
 *   con las demás. Errores se loguean con repair_id para diagnóstico posterior.
 *   La transición + log en repair_status_logs van en la misma transacción —
 *   atomicidad garantizada por repair.
 *
 * Días configurables:
 *   `config('repairs.abandonment_days', 60)`. Default 60 días según decisión
 *   de Mauricio el 2026-05-02.
 */
class MarkAbandonedRepairsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(): void
    {
        $thresholdDays = (int) config('repairs.abandonment_days', 60);

        $candidates = Repair::query()
            ->where('status', RepairStatus::ListoEntrega->value)
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', now()->subDays($thresholdDays))
            ->get(['id', 'repair_number', 'status', 'completed_at']);

        if ($candidates->isEmpty()) {
            return;
        }

        $marked = 0;
        $failed = 0;

        foreach ($candidates as $repair) {
            try {
                DB::transaction(function () use ($repair, $thresholdDays) {
                    $previousStatus = $repair->status;

                    $repair->update([
                        'status' => RepairStatus::Abandonada,
                        'abandoned_at' => now(),
                    ]);

                    $repair->statusLogs()->create([
                        'event_type' => RepairLogEvent::StatusChange,
                        'from_status' => $previousStatus->value,
                        'to_status' => RepairStatus::Abandonada->value,
                        'changed_by' => null, // sistema
                        'metadata' => [
                            'reason' => 'auto_abandoned',
                            'threshold_days' => $thresholdDays,
                            'days_in_ready' => (int) $repair->completed_at->diffInDays(now()),
                        ],
                        'note' => "Marcada como abandonada automáticamente: {$thresholdDays} días sin recoger.",
                    ]);
                });
                $marked++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('MarkAbandonedRepairsJob: fallo procesando repair', [
                    'repair_id' => $repair->id,
                    'repair_number' => $repair->repair_number,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('MarkAbandonedRepairsJob completado', [
            'candidates' => $candidates->count(),
            'marked' => $marked,
            'failed' => $failed,
        ]);
    }
}

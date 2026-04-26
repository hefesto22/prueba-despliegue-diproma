<?php

namespace App\Listeners;

use App\Events\CaiFailoverExecuted;
use App\Services\Invoicing\Exceptions\CaiSinSucesorException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Persiste en `activity_log` cada promoción automática de CAI sucesor.
 *
 * Va por cola para aislar la auditoría del camino crítico del failover:
 * si la DB de activity_log tuviera un problema transitorio, el reintento
 * lo maneja Horizon sin contaminar el reporte que produjo el Service.
 *
 * Log name 'cai_failover' (separado del 'default' de los modelos con
 * LogsActivity): permite al contador filtrar/exportar solo eventos fiscales
 * críticos sin mezclar con el ruido de CRUD habitual.
 *
 * No se asigna `causedBy()` deliberadamente — las activaciones automáticas
 * las dispara el sistema (job en background), no un usuario humano. El causer
 * `null` debe verse claramente distinto en reportes de auditoría frente a un
 * `causedBy(<user>)` que representa un cambio manual de admin.
 *
 * `performedOn($newCai)`: el "objeto sobre el que se actuó" es el sucesor
 * promovido (el que queda activo). Así la línea de tiempo del CAI nuevo
 * incluye su promoción, y un filtro `Activity::forSubject($caiNuevo)`
 * muestra el origen del relevo.
 */
class LogCaiFailoverActivity implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $backoff = 10;

    public function handle(CaiFailoverExecuted $event): void
    {
        $old = $event->result->oldCai;
        $new = $event->result->newCai;

        activity('cai_failover')
            ->performedOn($new)
            ->withProperties([
                'reason' => $event->result->reason,
                'reason_human' => $this->humanReason($event->result->reason),
                'document_type' => $new->document_type,
                'establishment_id' => $new->establishment_id,
                'old_cai' => [
                    'id' => $old->id,
                    'cai' => $old->cai,
                    'prefix' => $old->prefix,
                    'range_start' => $old->range_start,
                    'range_end' => $old->range_end,
                    'current_number' => $old->current_number,
                    'expiration_date' => $old->expiration_date?->toDateString(),
                ],
                'new_cai' => [
                    'id' => $new->id,
                    'cai' => $new->cai,
                    'prefix' => $new->prefix,
                    'range_start' => $new->range_start,
                    'range_end' => $new->range_end,
                    'expiration_date' => $new->expiration_date?->toDateString(),
                ],
            ])
            ->event('failover_executed')
            ->log(sprintf(
                'Failover automático de CAI: se promovió %s (ID %d) reemplazando a %s (ID %d, %s).',
                $new->cai,
                $new->id,
                $old->cai,
                $old->id,
                $this->humanReason($event->result->reason),
            ));
    }

    private function humanReason(string $reason): string
    {
        return match ($reason) {
            CaiSinSucesorException::REASON_EXPIRED => 'vencido',
            CaiSinSucesorException::REASON_EXHAUSTED => 'agotado',
            default => $reason,
        };
    }
}

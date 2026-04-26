<?php

namespace App\Events;

use App\Services\Alerts\DTOs\CaiFailoverResult;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento de dominio: se promovió automáticamente un CAI sucesor porque el
 * CAI activo quedó inutilizable (vencido o agotado).
 *
 * Producido por `CaiFailoverService::attemptFailoverFor()` después de que
 * `CaiRange::activate()` completa exitosamente. Los listeners reaccionan a
 * este evento sin que el Service conozca sus detalles:
 *
 *   - `LogCaiFailoverActivity` → persiste auditoría en `activity_log`.
 *   - (futuro) notificaciones informativas a contador cuando sea low-priority.
 *
 * Diseño del payload: se transporta el `CaiFailoverResult` completo en lugar
 * de los dos modelos sueltos. El Result ya encapsula la razón del failover
 * (expired/exhausted) — duplicar esa información en parámetros del constructor
 * invitaría a desincronización.
 *
 * `SerializesModels` permite que los listeners `ShouldQueue` puedan ser
 * despachados a cola sin romper la serialización de los CaiRange embebidos.
 */
class CaiFailoverExecuted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CaiFailoverResult $result,
    ) {}
}

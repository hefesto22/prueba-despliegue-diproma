<?php

namespace App\Exceptions\Cash;

use RuntimeException;

/**
 * Se lanza al intentar abrir una nueva sesión de caja en una sucursal que
 * tiene una sesión auto-cerrada (cerrada por AutoCloseCashSessionsJob)
 * pendiente de conciliación con más de N días de antigüedad.
 *
 * Razón de la regla: el cierre automático del sistema NO calcula
 * actual_closing_amount (no contó plata físicamente). Si la sesión queda
 * sin conciliar indefinidamente, el kardex pierde una pieza fundamental:
 * no hay registro del conteo físico real al cierre. Bloquear apertura
 * después de un umbral razonable (default 7 días) fuerza al cajero/admin
 * a conciliar antes de seguir operando.
 *
 * Se lanza en CashSessionService::open() después de detectar la sesión
 * pendiente. NO se lanza durante la operación normal de la sesión actual
 * (no afecta facturación ni movimientos en curso).
 *
 * El umbral de días es configurable vía config('cash.reconciliation_grace_days').
 */
final class ConciliacionPendienteException extends RuntimeException
{
    public function __construct(
        public readonly int $establishmentId,
        public readonly int $pendingSessionId,
        public readonly int $daysSinceAutoClose,
        public readonly int $thresholdDays,
    ) {
        parent::__construct(sprintf(
            'No se puede abrir una nueva caja en la sucursal #%d: la sesión #%d '
            . 'fue auto-cerrada por el sistema hace %d días y aún no fue conciliada. '
            . 'Conciliá esa sesión (ingresá el conteo físico real) antes de abrir otra. '
            . 'Umbral configurado: %d días.',
            $establishmentId,
            $pendingSessionId,
            $daysSinceAutoClose,
            $thresholdDays,
        ));
    }
}

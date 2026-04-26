<?php

namespace App\Exceptions\Cash;

use RuntimeException;

/**
 * Se lanza al intentar abrir una segunda sesión de caja en una sucursal
 * que ya tiene una sesión activa (closed_at IS NULL).
 *
 * Razón de la regla: una caja física no puede operar dos sesiones simultáneas
 * sin perder trazabilidad. El relevo entre cajeros debe pasar por un cierre
 * explícito de la sesión anterior + apertura nueva con conteo físico.
 *
 * Esta excepción nace en `CashSessionService::open()` después del
 * `lockForUpdate` que detecta la sesión abierta existente. Es prevención
 * defense-in-depth además del unique parcial en `closed_at` a nivel DB.
 */
final class CajaYaAbiertaException extends RuntimeException
{
    public function __construct(
        public readonly int $establishmentId,
        public readonly int $existingSessionId,
    ) {
        parent::__construct(sprintf(
            'Ya existe una sesión de caja abierta (#%d) para la sucursal #%d. '
            . 'Cerrá la sesión actual antes de abrir una nueva.',
            $existingSessionId,
            $establishmentId,
        ));
    }
}

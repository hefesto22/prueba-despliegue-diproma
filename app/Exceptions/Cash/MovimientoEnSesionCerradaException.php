<?php

namespace App\Exceptions\Cash;

use RuntimeException;

/**
 * Se lanza al intentar registrar un movimiento en una sesión de caja ya
 * cerrada (closed_at IS NOT NULL).
 *
 * Una sesión cerrada es un registro histórico inmutable. Agregar movimientos
 * post-cierre rompería el `expected_closing_amount` congelado y dejaría
 * inconsistente el kardex de caja.
 *
 * Si el cajero olvidó registrar un gasto antes de cerrar, el flujo correcto
 * es abrir una nueva sesión y registrarlo ahí como `adjustment` con
 * justificación explícita — no "reabrir" la anterior.
 */
final class MovimientoEnSesionCerradaException extends RuntimeException
{
    public function __construct(
        public readonly int $sessionId,
    ) {
        parent::__construct(sprintf(
            'La sesión de caja #%d ya está cerrada. No se pueden registrar '
            . 'movimientos en sesiones cerradas. Abrí una nueva sesión y '
            . 'registrá un ajuste con la justificación correspondiente.',
            $sessionId,
        ));
    }
}

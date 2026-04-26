<?php

declare(strict_types=1);

namespace App\Services\Purchases\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando en un mismo día calendario ya se emitieron 9999 Recibos
 * Internos y se intenta emitir el 10,000. Escenario operativamente absurdo
 * en un comercio físico — indicador de uso incorrecto del RI (el operador
 * probablemente está registrando con RI compras que sí tienen CAI).
 *
 * Si este caso llega a presentarse legítimamente, la solución es ampliar el
 * formato (RI-YYYYMMDD-NNNNN) — no suprimir el límite.
 */
class LimiteReciboInternoDiarioException extends RuntimeException
{
    public function __construct(
        public readonly string $fecha,
        public readonly int $maximo,
    ) {
        parent::__construct(
            "Se alcanzó el límite diario de Recibos Internos ({$maximo}) para la fecha {$fecha}. "
            .'Revise si se está usando RI para compras con CAI (uso incorrecto) o amplíe el formato del correlativo.'
        );
    }
}

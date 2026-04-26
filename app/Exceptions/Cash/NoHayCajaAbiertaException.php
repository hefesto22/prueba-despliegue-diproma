<?php

namespace App\Exceptions\Cash;

use RuntimeException;

/**
 * Se lanza al intentar operar sobre una caja inexistente (aún no abierta
 * o ya cerrada) en una sucursal dada.
 *
 * Casos típicos:
 *   - POS intenta registrar una venta pero ningún cajero abrió caja del día.
 *   - Un movimiento manual (gasto, depósito) no encuentra sesión abierta.
 *
 * La respuesta correcta del sistema es bloquear la operación y pedir abrir
 * caja explícitamente — nunca "abrir implícitamente" porque el monto de
 * apertura (conteo físico) es un dato que solo el humano puede proveer.
 */
final class NoHayCajaAbiertaException extends RuntimeException
{
    public function __construct(
        public readonly int $establishmentId,
    ) {
        parent::__construct(sprintf(
            'No hay una sesión de caja abierta para la sucursal #%d. '
            . 'El cajero debe abrir caja antes de registrar movimientos.',
            $establishmentId,
        ));
    }
}

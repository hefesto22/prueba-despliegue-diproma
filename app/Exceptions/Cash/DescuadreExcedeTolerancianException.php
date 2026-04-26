<?php

namespace App\Exceptions\Cash;

use RuntimeException;

/**
 * Se lanza al intentar cerrar una caja con |discrepancy| mayor a la
 * tolerancia configurada en `company_settings.cash_discrepancy_tolerance`,
 * SIN proveer un `authorized_by_user_id` que firme la autorización.
 *
 * Flujo correcto cuando ocurre:
 *   1. La UI captura la excepción y pide al cajero seleccionar un usuario
 *      con rol admin/gerente para autorizar.
 *   2. El cajero reintenta el cierre pasando `authorized_by_user_id`.
 *   3. `CashSessionService::close()` valida que el autorizador tenga permiso
 *      y completa el cierre con el registro de quien firmó.
 *
 * El descuadre NO se ignora ni se silencia — se documenta explícitamente
 * con quién firmó, dejando trazabilidad para auditoría interna.
 */
final class DescuadreExcedeTolerancianException extends RuntimeException
{
    public function __construct(
        public readonly int $sessionId,
        public readonly float $discrepancy,
        public readonly float $tolerance,
    ) {
        parent::__construct(sprintf(
            'El descuadre de caja (L. %s) supera la tolerancia configurada '
            . '(L. %s) para la sesión #%d. Requiere autorización de un usuario '
            . 'con permisos para continuar el cierre.',
            number_format(abs($discrepancy), 2),
            number_format($tolerance, 2),
            $sessionId,
        ));
    }
}

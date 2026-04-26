<?php

namespace App\Services\FiscalPeriods\Exceptions;

/**
 * Se lanza cuando se intenta crear un snapshot de declaración ISV mensual
 * para un período fiscal que ya tiene un snapshot ACTIVO (no supersedido).
 *
 * Por qué existe esta regla:
 *   Cada período fiscal puede tener N snapshots históricos pero solo UNO
 *   "vigente" — el original o la última rectificativa. La columna virtual
 *   `is_active` + el UNIQUE compuesto `(fiscal_period_id, is_active)` hace
 *   cumplir esto a nivel DB; esta excepción es la versión a nivel Service
 *   que se lanza ANTES de llegar a la DB para dar un mensaje accionable
 *   al contador en vez de un `QueryException` críptico de MySQL.
 *
 * Cómo se debe corregir:
 *   1. Si la declaración previa fue errónea: reabrir el período fiscal
 *      (FiscalPeriodService::reopen) y luego re-declarar — el Service
 *      marcará el snapshot anterior como `superseded_at` automáticamente
 *      y creará el nuevo como activo.
 *   2. Si fue un intento accidental de re-declarar: cancelar la operación.
 *
 * Referencia: Acuerdo SAR 189-2014 (procedimiento de declaración rectificativa).
 */
class DeclaracionIsvYaExisteException extends FiscalPeriodException
{
    /**
     * @param  int  $periodYear            Año del período con snapshot activo.
     * @param  int  $periodMonth           Mes del período con snapshot activo.
     * @param  int  $existingDeclarationId ID del snapshot que ya está activo
     *                                     — útil para que el caller pueda
     *                                     redirigir al usuario al registro.
     */
    public function __construct(
        public readonly int $periodYear,
        public readonly int $periodMonth,
        public readonly int $existingDeclarationId,
    ) {
        $periodo = str_pad((string) $periodMonth, 2, '0', STR_PAD_LEFT) . "/{$periodYear}";

        parent::__construct(
            "Ya existe una declaración ISV activa para el período {$periodo} "
            . "(ID #{$this->existingDeclarationId}). Para corregirla, reabra el "
            . 'período fiscal como rectificativa y vuelva a declarar — el sistema '
            . 'marcará la declaración anterior como reemplazada automáticamente.'
        );
    }
}

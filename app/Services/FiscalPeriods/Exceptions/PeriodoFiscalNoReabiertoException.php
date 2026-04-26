<?php

namespace App\Services\FiscalPeriods\Exceptions;

/**
 * Se lanza cuando se intenta `redeclare()` un período fiscal que NO fue
 * previamente reabierto.
 *
 * Por qué existe esta regla:
 *   Una declaración rectificativa SAR (Acuerdo 189-2014) requiere un acto
 *   formal de reapertura del período antes de modificar la declaración
 *   original. Saltarse ese paso produce dos problemas:
 *     1. Auditoría rota — no queda registrado QUIÉN autorizó la rectificativa
 *        ni POR QUÉ (el `reason` se captura en `FiscalPeriodService::reopen`).
 *     2. Snapshots silenciosos — el contador podría sobreescribir una
 *        declaración cerrada sin trazabilidad, contradiciendo el modelo de
 *        snapshots inmutables del sistema.
 *
 *   Esta excepción fuerza al caller a invocar primero
 *   `FiscalPeriodService::reopen($periodo, $usuario, $razon)` y solo entonces
 *   `IsvMonthlyDeclarationService::redeclare(...)`.
 *
 * Detección:
 *   `FiscalPeriod::wasReopened()` es true si y solo si `reopened_at` no es
 *   null. Si el período está abierto pero nunca fue reabierto, el flujo
 *   correcto es `declare()`, no `redeclare()`.
 *
 * Referencia: Acuerdo SAR 189-2014 (Sección IV — Declaración Rectificativa).
 */
class PeriodoFiscalNoReabiertoException extends FiscalPeriodException
{
    /**
     * @param  int  $periodYear   Año del período sobre el que se intentó redeclarar.
     * @param  int  $periodMonth  Mes del período sobre el que se intentó redeclarar.
     */
    public function __construct(
        public readonly int $periodYear,
        public readonly int $periodMonth,
    ) {
        $periodo = str_pad((string) $periodMonth, 2, '0', STR_PAD_LEFT) . "/{$periodYear}";

        parent::__construct(
            "No se puede emitir una declaración rectificativa para el período {$periodo} "
            . 'porque nunca fue reabierto. Para rectificar una declaración previa, primero '
            . 'reabra el período fiscal documentando la razón (FiscalPeriodService::reopen) '
            . 'y luego invoque redeclare(). Si es la primera declaración del período, use declare().'
        );
    }
}

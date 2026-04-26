<?php

namespace App\Services\FiscalPeriods\Exceptions;

use Carbon\CarbonInterface;

/**
 * Se lanza cuando se intenta reabrir un período que ya está abierto
 * (nunca fue declarado, o fue reabierto previamente y aún no se re-declaró).
 *
 * Reabrir un período abierto no tiene sentido operativo: el período ya
 * admite anulaciones y correcciones. Esta excepción evita confusión en
 * la UI al marcar acciones inconsistentes.
 */
class PeriodoFiscalYaReabiertoException extends FiscalPeriodException
{
    public function __construct(
        public readonly int $periodYear,
        public readonly int $periodMonth,
        public readonly ?CarbonInterface $reopenedAt = null,
    ) {
        $periodo = str_pad((string) $periodMonth, 2, '0', STR_PAD_LEFT) . "/{$periodYear}";

        $detalle = $reopenedAt
            ? "ya fue reabierto el {$reopenedAt->format('d/m/Y H:i')} y aún no se ha vuelto a declarar"
            : 'todavía no ha sido declarado al SAR, por lo que sigue abierto';

        parent::__construct(
            "No se puede reabrir el período fiscal {$periodo}: {$detalle}. "
            . 'Un período abierto ya admite anulaciones y correcciones de facturas directamente.'
        );
    }
}

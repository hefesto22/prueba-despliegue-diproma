<?php

namespace App\Services\FiscalPeriods\Exceptions;

use Carbon\CarbonInterface;

/**
 * Se lanza cuando el contador intenta declarar un período que ya fue
 * declarado previamente. La operación correcta para modificar una
 * declaración vigente es reopen() seguido de declare() nuevamente.
 */
class PeriodoFiscalYaDeclaradoException extends FiscalPeriodException
{
    public function __construct(
        public readonly int $periodYear,
        public readonly int $periodMonth,
        public readonly CarbonInterface $declaredAt,
    ) {
        $periodo = str_pad((string) $periodMonth, 2, '0', STR_PAD_LEFT) . "/{$periodYear}";

        parent::__construct(
            "El período fiscal {$periodo} ya fue declarado el "
            . "{$declaredAt->format('d/m/Y H:i')}. "
            . 'Si necesita modificarlo, solicite a un administrador reabrir el período '
            . 'y luego presentar declaración rectificativa.'
        );
    }
}

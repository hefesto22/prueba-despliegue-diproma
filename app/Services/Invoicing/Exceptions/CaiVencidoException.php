<?php

namespace App\Services\Invoicing\Exceptions;

use Carbon\CarbonInterface;

class CaiVencidoException extends InvoicingException
{
    public function __construct(
        public readonly int $caiRangeId,
        public readonly string $cai,
        public readonly CarbonInterface $expirationDate,
    ) {
        parent::__construct(
            "El CAI {$cai} (ID {$caiRangeId}) venció el {$expirationDate->format('d/m/Y')}. "
            . "Solicite y registre un nuevo CAI ante SAR antes de continuar emitiendo."
        );
    }
}

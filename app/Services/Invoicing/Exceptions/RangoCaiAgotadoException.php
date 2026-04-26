<?php

namespace App\Services\Invoicing\Exceptions;

class RangoCaiAgotadoException extends InvoicingException
{
    public function __construct(
        public readonly int $caiRangeId,
        public readonly string $cai,
        public readonly int $rangeEnd,
    ) {
        parent::__construct(
            "El rango del CAI {$cai} (ID {$caiRangeId}) agotó su último folio ({$rangeEnd}). "
            . "Registre un nuevo rango CAI en Administración antes de continuar emitiendo."
        );
    }
}

<?php

namespace App\Services\CreditNotes\Exceptions;

/**
 * Se intenta anular una NC que ya está en estado anulada (is_void = true).
 *
 * Fail fast: sin esta guarda, el flujo intentaría revertir kardex de nuevo
 * — duplicaría la SalidaAnulacionNotaCredito y descuadraría stock. El lock
 * transaccional (lockForUpdate) de voidNotaCredito() garantiza que dos
 * llamadas concurrentes sobre la misma NC se serialicen: la primera marca
 * is_void=true, la segunda al refrescar dentro del lock encuentra is_void=true
 * y lanza esta excepción en vez de proceder.
 */
class NotaCreditoYaAnuladaException extends CreditNoteException
{
    public function __construct(
        public readonly int $creditNoteId,
        public readonly string $creditNoteNumber,
    ) {
        parent::__construct(
            "La nota de crédito {$creditNoteNumber} (#{$creditNoteId}) ya fue anulada "
            . 'y no puede anularse nuevamente.'
        );
    }
}

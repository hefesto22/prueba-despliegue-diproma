<?php

namespace App\Services\CreditNotes\Exceptions;

/**
 * Se intenta emitir una NC sobre una factura sin CAI (without_cai=true).
 *
 * Una factura "sin CAI" es una referencia interna, no un documento fiscal
 * del régimen SAR. Emitir una NC sobre ella generaría una cadena de
 * documentos sin validez fiscal. Si hay que revertir una venta sin CAI,
 * el flujo correcto es anular la venta (cancel) directamente — eso ya
 * regresa el stock al kardex por la vía EntradaAnulacionVenta.
 */
class FacturaWithoutCaiNoAcreditableException extends CreditNoteException
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $invoiceNumber,
    ) {
        parent::__construct(
            "La factura #{$invoiceNumber} (id {$invoiceId}) fue emitida sin CAI y no puede "
            . "acreditarse. Para revertirla anule la venta directamente desde el módulo de "
            . "ventas."
        );
    }
}

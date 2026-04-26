<?php

namespace App\Services\CreditNotes\Exceptions;

/**
 * Se intenta emitir una NC sobre una factura que ya está anulada (is_void=true).
 *
 * Una factura anulada ya no produce efecto fiscal — no puede acreditarse.
 * Si el usuario necesita corregir algo de esa venta, el flujo correcto es
 * re-facturar, no acreditar una factura sin validez vigente.
 */
class FacturaAnuladaNoAcreditableException extends CreditNoteException
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $invoiceNumber,
    ) {
        parent::__construct(
            "La factura #{$invoiceNumber} (id {$invoiceId}) está anulada y no admite "
            . "la emisión de una nota de crédito. Si necesita corregir la venta original, "
            . "emita una nueva factura."
        );
    }
}

<?php

namespace App\Services\CreditNotes\Exceptions;

/**
 * Se intenta acreditar una cantidad que excede lo aún acreditable de una línea
 * de factura original (sale_item), considerando NCs previas no anuladas sobre
 * la misma línea.
 *
 * Por ejemplo: factura original vendió 10 unidades del producto X; ya existe
 * una NC vigente por 4 unidades; la nueva NC no puede acreditar más de 6.
 *
 * La validación acumulativa se hace dentro de la transacción con lockForUpdate
 * sobre la factura original para evitar race conditions entre NCs concurrentes.
 */
class CantidadYaAcreditadaException extends CreditNoteException
{
    public function __construct(
        public readonly int $saleItemId,
        public readonly int $productId,
        public readonly int $solicitada,
        public readonly int $disponible,
        public readonly int $yaAcreditada,
    ) {
        parent::__construct(
            "Cantidad solicitada ({$solicitada}) excede el saldo acreditable "
            . "({$disponible}) de la línea #{$saleItemId} del producto #{$productId}. "
            . "Ya se acreditaron {$yaAcreditada} unidades en notas de crédito previas."
        );
    }
}

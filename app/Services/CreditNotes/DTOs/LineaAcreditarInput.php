<?php

namespace App\Services\CreditNotes\DTOs;

use InvalidArgumentException;

/**
 * Value Object inmutable: una línea a acreditar en una Nota de Crédito.
 *
 * Identifica la línea de la factura origen (`saleItemId`) y la cantidad
 * solicitada para acreditar. El precio unitario NO se pasa — se toma
 * del snapshot del SaleItem original. Esto es intencional: la NC acredita
 * exactamente lo facturado, no un precio distinto.
 *
 * La validación de cantidades disponibles (considerando NCs previas no
 * anuladas) la hace el servicio — este VO solo garantiza que la entrada
 * al servicio sea sintácticamente válida.
 */
final class LineaAcreditarInput
{
    public function __construct(
        public readonly int $saleItemId,
        public readonly int $quantity,
    ) {
        if ($saleItemId <= 0) {
            throw new InvalidArgumentException(
                "saleItemId debe ser > 0, recibido: {$saleItemId}"
            );
        }
        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                "quantity debe ser > 0, recibido: {$quantity}"
            );
        }
    }
}

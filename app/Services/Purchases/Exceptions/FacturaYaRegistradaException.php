<?php

declare(strict_types=1);

namespace App\Services\Purchases\Exceptions;

use RuntimeException;

/**
 * La factura del proveedor ya está registrada (vigente) en Compras.
 *
 * Registrarla dos veces duplicaría su ISV en el Libro de Compras y en el
 * crédito fiscal declarado. Pasa típicamente cuando la misma factura se
 * registra una vez en Compras y otra como gasto con factura.
 */
class FacturaYaRegistradaException extends RuntimeException
{
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly string $purchaseNumber,
    ) {
        parent::__construct(
            "La factura {$invoiceNumber} de este proveedor ya está registrada en Compras ({$purchaseNumber}). "
            .'Registrarla otra vez duplicaría su ISV en el Libro de Compras.'
        );
    }
}

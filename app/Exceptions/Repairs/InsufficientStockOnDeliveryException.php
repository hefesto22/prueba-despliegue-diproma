<?php

namespace App\Exceptions\Repairs;

use App\Models\Product;

/**
 * Stock insuficiente al entregar una reparación.
 *
 * Caso edge: entre la cotización (donde validamos stock) y la entrega,
 * alguien vendió la pieza desde el POS. La entrega falla con bloqueo duro
 * — el cajero/admin debe decidir reemplazar la pieza con una externa o
 * anular la reparación.
 */
class InsufficientStockOnDeliveryException extends RepairDeliveryException
{
    public function __construct(
        public readonly Product $product,
        public readonly float $requested,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            'Stock insuficiente para "%s". Cotizado: %s · Disponible ahora: %d. '
            . 'Reemplaza la pieza por una externa o anula la reparación.',
            $product->name,
            number_format($requested, 2),
            $available,
        ));
    }
}

<?php

namespace App\Exceptions\Repairs;

use App\Models\Product;
use App\Models\Repair;

/**
 * Excepción base para errores en el flujo de entrega de una Reparación.
 *
 * Subclases específicas:
 *   - InsufficientStockOnDeliveryException: stock 0 en alguna pieza interna
 *     entre la cotización y la entrega (alguien la vendió desde el POS).
 */
class RepairDeliveryException extends \DomainException
{
}

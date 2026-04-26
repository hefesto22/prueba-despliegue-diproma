<?php

namespace App\Services\Sales\Tax;

/**
 * Value Object de salida por línea del SaleTaxCalculator.
 *
 * Contiene el desglose fiscal de una TaxableLine:
 *   - subtotal: base sin ISV
 *   - isv: monto del impuesto
 *   - total: línea con ISV (subtotal + isv, siempre coincide con TaxableLine::lineTotal())
 *   - identity: el mismo identity que trajo la TaxableLine (opaco al calculator)
 *
 * NOTA: El breakdown por línea NO tiene el descuento aplicado. El descuento
 * se aplica proporcionalmente solo a los totales agregados en TaxBreakdown.
 * Esto preserva los line totals originales para el Blade/PDF del POS (igual
 * a como se comportaba la implementación anterior duplicada).
 *
 * Inmutable por construcción — readonly + final.
 */
final class LineBreakdown
{
    public function __construct(
        public readonly mixed $identity,
        public readonly float $subtotal,
        public readonly float $isv,
        public readonly float $total,
    ) {}
}

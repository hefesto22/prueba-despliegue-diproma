<?php

namespace App\Services\Repairs\Tax;

/**
 * Value Object de salida por línea — qué calculó el RepairTaxCalculator.
 *
 * Símil de `LineBreakdown` del módulo Sales pero independiente para no
 * acoplar dominios. Si en el futuro un dominio cambia su estructura
 * (ej: agregar `discountAllocated`), no afecta al otro.
 */
final class RepairLineBreakdown
{
    public function __construct(
        public readonly mixed $identity,
        public readonly float $subtotal,    // base sin ISV
        public readonly float $isv,         // ISV de la línea
        public readonly float $total,       // total con ISV (= subtotal + isv)
    ) {}
}

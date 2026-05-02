<?php

namespace App\Services\Repairs\Tax;

use App\Enums\TaxType;

/**
 * Value Object de entrada para RepairTaxCalculator.
 *
 * Representa una línea de cotización de reparación sobre la que se va a
 * calcular el desglose fiscal: precio unitario (con ISV si gravado),
 * cantidad (decimal porque las honorarios pueden ser por horas fraccionarias),
 * tipo de impuesto e identidad opcional para mapear el resultado al
 * RepairItem original.
 *
 * Inmutable por construcción: readonly + final. Cualquier cambio genera
 * una instancia nueva — elimina mutaciones accidentales en el ciclo
 * `#[Computed]` de Livewire (RepairItemsRelationManager preview).
 *
 * Diferencia con SaleTaxableLine: aquí `quantity` es float (1.5 horas) y no
 * int. Las piezas físicas siempre serán enteras pero el dominio lo permite
 * a nivel del VO para no duplicar la clase.
 */
final class RepairTaxableLine
{
    public function __construct(
        public readonly float $unitPrice,
        public readonly float $quantity,
        public readonly TaxType $taxType,
        public readonly mixed $identity = null,
    ) {
        if ($unitPrice < 0) {
            throw new \InvalidArgumentException(
                "RepairTaxableLine: unit_price no puede ser negativo. Recibido: {$unitPrice}"
            );
        }

        if ($quantity <= 0) {
            throw new \InvalidArgumentException(
                "RepairTaxableLine: quantity debe ser > 0. Recibido: {$quantity}"
            );
        }
    }

    /**
     * Total bruto de la línea (precio con ISV × cantidad), redondeado a 2 decimales.
     */
    public function lineTotal(): float
    {
        return round($this->unitPrice * $this->quantity, 2);
    }
}

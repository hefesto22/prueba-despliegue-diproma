<?php

namespace App\Services\Sales\Tax;

use App\Enums\TaxType;

/**
 * Value Object de entrada para SaleTaxCalculator.
 *
 * Representa una línea sobre la que se va a calcular el desglose fiscal:
 * precio unitario, cantidad, tipo de impuesto e identidad opcional.
 *
 * `identity` es un acarreo arbitrario que el caller usa para mapear el
 * resultado de vuelta a su modelo de origen (ej. SaleItem::id en
 * persistencia post-venta, null o SKU en preview del POS donde no hay ID
 * todavía). El calculator NO interpreta identity — solo lo devuelve en el
 * LineBreakdown correspondiente.
 *
 * Inmutable por construcción: readonly + final. Cualquier cambio genera
 * una instancia nueva — elimina la clase completa de bugs por mutación
 * accidental dentro del #[Computed] de Livewire o del foreach de persistencia.
 */
final class TaxableLine
{
    public function __construct(
        public readonly float $unitPrice,
        public readonly int $quantity,
        public readonly TaxType $taxType,
        public readonly mixed $identity = null,
    ) {
        if ($unitPrice < 0) {
            throw new \InvalidArgumentException(
                "TaxableLine: unit_price no puede ser negativo. Recibido: {$unitPrice}"
            );
        }

        if ($quantity <= 0) {
            throw new \InvalidArgumentException(
                "TaxableLine: quantity debe ser > 0. Recibido: {$quantity}"
            );
        }
    }

    /**
     * Total bruto de la línea (precio con ISV × cantidad), redondeado a 2 decimales.
     * Usado internamente por el calculator — expuesto para casos donde el caller
     * quiere mostrarlo sin reconstruir la lógica de redondeo.
     */
    public function lineTotal(): float
    {
        return round($this->unitPrice * $this->quantity, 2);
    }
}

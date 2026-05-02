<?php

namespace App\Services\Repairs\Tax;

/**
 * Value Object de salida del RepairTaxCalculator.
 *
 * Contiene los totales agregados de la cotización completa más el desglose
 * por línea para que el caller mapee de vuelta a RepairItem persistidos.
 *
 * Diferencia con TaxBreakdown de Sales: aquí NO hay descuento. Las
 * cotizaciones de reparación no usan descuento global (el técnico ajusta
 * el precio unitario directamente). Si en el futuro se agrega, va aquí
 * sin afectar Sales.
 */
final class RepairTaxBreakdown
{
    /**
     * @param  RepairLineBreakdown[]  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly float $subtotal,      // suma de base sin ISV (todas las líneas)
        public readonly float $exemptTotal,   // base de líneas exentas
        public readonly float $taxableTotal,  // base de líneas gravadas
        public readonly float $isv,           // ISV total
        public readonly float $total,         // total con ISV
    ) {}

    public static function empty(): self
    {
        return new self(
            lines: [],
            subtotal: 0.0,
            exemptTotal: 0.0,
            taxableTotal: 0.0,
            isv: 0.0,
            total: 0.0,
        );
    }
}

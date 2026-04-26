<?php

namespace App\Services\Sales\Tax;

/**
 * Value Object de salida agregado del SaleTaxCalculator.
 *
 * Contiene el desglose fiscal completo de la venta:
 *   - subtotal / isv / total: totales agregados POST descuento (listos para persistir
 *     en Sale o mostrar en el POS).
 *   - grossTotal: total antes de descuento (útil para UI que muestra el "tachado").
 *   - discountAmount: monto efectivo del descuento aplicado (clampeado a grossTotal
 *     — si el caller pidió un descuento superior al total, el calculator lo recorta
 *     antes de distribuir y reporta el monto real aplicado).
 *   - lines: array de LineBreakdown, uno por cada TaxableLine de entrada, en el
 *     mismo orden (para mapeo posicional) y accesible por identity (para mapeo
 *     a Eloquent models).
 *
 * Inmutable por construcción — readonly + final.
 */
final class TaxBreakdown
{
    /**
     * @param  LineBreakdown[]  $lines
     */
    public function __construct(
        public readonly float $subtotal,
        public readonly float $isv,
        public readonly float $total,
        public readonly float $grossTotal,
        public readonly float $discountAmount,
        public readonly array $lines,
    ) {}

    /**
     * Breakdown vacío — caso carrito sin líneas. Se usa en el POS para
     * devolver ceros sin tener que instanciar líneas ni pasar por el
     * foreach del calculator.
     */
    public static function empty(): self
    {
        return new self(
            subtotal: 0.0,
            isv: 0.0,
            total: 0.0,
            grossTotal: 0.0,
            discountAmount: 0.0,
            lines: [],
        );
    }

    /**
     * Lookup de breakdown por identity. Uso típico: mapear de vuelta a los
     * SaleItem en SaleService después del cálculo para persistir los line totals.
     *
     * Comparación por === para evitar coerción. Si el caller no pasó identity
     * en las TaxableLine (caso POS preview), este método no aplica.
     */
    public function lineFor(mixed $identity): ?LineBreakdown
    {
        foreach ($this->lines as $line) {
            if ($line->identity === $identity) {
                return $line;
            }
        }

        return null;
    }
}

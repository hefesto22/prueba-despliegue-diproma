<?php

namespace App\Services\Sales\Tax;

use App\Enums\TaxType;

/**
 * Calculador fiscal puro para ventas.
 *
 * Fuente única de verdad del cálculo subtotal/ISV/total con descuento en
 * el proyecto. Reemplaza la duplicación previa que vivía tanto en
 * `PointOfSale::taxBreakdown()` (preview del POS en Livewire `#[Computed]`)
 * como en `SaleService::calculateTotals()` (post-persistencia). La misma
 * regla fiscal ahora solo existe aquí — cambiarla (ej. SAR ajusta redondeo,
 * nueva tasa dual 15%/18%, canasta exenta) es un solo edit con tests.
 *
 * Propiedades de la implementación:
 *   - Pura: no lee DB, no dispara eventos, no muta estado. Dado el mismo
 *     input devuelve el mismo output (idempotente).
 *   - Stateless: el único estado es `$multiplier` inyectado por constructor,
 *     constante durante la vida del container. Por eso se registra como
 *     singleton en AppServiceProvider.
 *   - Rápida: O(n) sobre las líneas, sin allocaciones ocultas más allá de los
 *     LineBreakdown devueltos. Apta para ejecutarse en el ciclo `#[Computed]`
 *     de Livewire que puede disparar múltiples veces por request.
 *
 * Regla de negocio implementada:
 *   1. Por cada línea: si Gravado15 descomponer `lineTotal / multiplier` →
 *      `lineBase`, y `lineIsv = lineTotal - lineBase` (NUNCA `lineBase * rate`
 *      porque acumula error de redondeo y desplaza el total en centavos).
 *      Si Exento: `lineBase = lineTotal`, `lineIsv = 0`.
 *   2. Sumar `grossTotal`, `totalBase`, `totalIsv`.
 *   3. Si hay descuento: clamp a `grossTotal` (nunca descuento > total),
 *      calcular `discountRatio = effectiveDiscount / grossTotal`, y reducir
 *      `totalBase` y `totalIsv` proporcionalmente. El descuento NO se aplica
 *      a cada LineBreakdown individual — los line totals preservan el valor
 *      original para impresión del ticket/factura (mismo comportamiento que
 *      la implementación anterior).
 *   4. `total = round(totalBase + totalIsv, 2)`.
 *
 * DIP: se inyecta por constructor con `$multiplier` extraído de config en el
 * binding (ver AppServiceProvider). Los tests construyen instancias nuevas con
 * cualquier multiplier — no dependen del container ni de `config()`.
 */
final class SaleTaxCalculator
{
    public function __construct(
        private readonly float $multiplier = 1.15,
    ) {
        if ($multiplier <= 0) {
            throw new \InvalidArgumentException(
                "SaleTaxCalculator: multiplier debe ser > 0. Recibido: {$multiplier}"
            );
        }
    }

    /**
     * Calcular el desglose fiscal completo de una venta.
     *
     * @param  TaxableLine[]  $lines
     * @param  float  $discountAmount  Monto absoluto del descuento (HNL). El caller ya
     *                                 resolvió el tipo (monto fijo / porcentaje) antes
     *                                 de invocar — el calculator no conoce tipos de
     *                                 descuento (SRP: matemática fiscal, no políticas).
     *                                 Se clampea a grossTotal si excede.
     *
     * @throws \InvalidArgumentException Si discountAmount es negativo.
     */
    public function calculate(array $lines, float $discountAmount = 0.0): TaxBreakdown
    {
        if ($discountAmount < 0) {
            throw new \InvalidArgumentException(
                "SaleTaxCalculator: discountAmount no puede ser negativo. Recibido: {$discountAmount}"
            );
        }

        if (empty($lines)) {
            return TaxBreakdown::empty();
        }

        $grossTotal = 0.0;
        $totalBase = 0.0;
        $totalIsv = 0.0;
        $lineBreakdowns = [];

        foreach ($lines as $line) {
            if (! $line instanceof TaxableLine) {
                throw new \InvalidArgumentException(
                    'SaleTaxCalculator::calculate() espera un array<TaxableLine>.'
                );
            }

            $lineTotal = $line->lineTotal();

            if ($line->taxType === TaxType::Gravado15) {
                $lineBase = round($lineTotal / $this->multiplier, 2);
                // Restar del total ya redondeado — evita drift de redondeo.
                $lineIsv = round($lineTotal - $lineBase, 2);
            } else {
                $lineBase = $lineTotal;
                $lineIsv = 0.0;
            }

            $lineBreakdowns[] = new LineBreakdown(
                identity: $line->identity,
                subtotal: $lineBase,
                isv: $lineIsv,
                total: $lineTotal,
            );

            $grossTotal += $lineTotal;
            $totalBase += $lineBase;
            $totalIsv += $lineIsv;
        }

        // Clamp: un descuento mayor al total se reduce al total (free).
        // Defensa contra políticas de descuento mal configuradas; el caller
        // puede revisar TaxBreakdown::discountAmount para saber cuánto se aplicó.
        $effectiveDiscount = min($discountAmount, $grossTotal);

        if ($effectiveDiscount > 0 && $grossTotal > 0) {
            $discountRatio = $effectiveDiscount / $grossTotal;
            $totalBase = round($totalBase * (1 - $discountRatio), 2);
            $totalIsv = round($totalIsv * (1 - $discountRatio), 2);
        }

        return new TaxBreakdown(
            subtotal: round($totalBase, 2),
            isv: round($totalIsv, 2),
            total: round($totalBase + $totalIsv, 2),
            grossTotal: round($grossTotal, 2),
            discountAmount: round($effectiveDiscount, 2),
            lines: $lineBreakdowns,
        );
    }

    /**
     * Helper para cuando el caller necesita el grossTotal ANTES de calcular el
     * descuento (ej. SaleService aplica DiscountType::calculateAmount($value, $grossTotal)
     * antes de invocar calculate()). Evita un double-pass al no obligar al caller
     * a iterar manualmente las líneas.
     *
     * @param  TaxableLine[]  $lines
     */
    public function grossTotal(array $lines): float
    {
        $sum = 0.0;
        foreach ($lines as $line) {
            if (! $line instanceof TaxableLine) {
                throw new \InvalidArgumentException(
                    'SaleTaxCalculator::grossTotal() espera un array<TaxableLine>.'
                );
            }
            $sum += $line->lineTotal();
        }

        return round($sum, 2);
    }
}

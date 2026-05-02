<?php

namespace App\Services\Repairs\Tax;

use App\Enums\TaxType;

/**
 * Calculador fiscal puro para cotizaciones de reparación.
 *
 * Fuente única de verdad del cálculo subtotal/ISV/total/exempt para el módulo
 * Repairs. Equivalente al SaleTaxCalculator pero independiente porque las
 * reparaciones tienen reglas distintas:
 *
 *   - SIN descuento global (cada línea es directa).
 *   - Diferenciación entre `taxable_total` (base gravada) y `exempt_total`
 *     (base exenta) en la salida — dato que la factura CAI necesita explícito
 *     para SAR.
 *   - `quantity` puede ser decimal (honorarios por horas).
 *
 * Propiedades:
 *   - Pura: no lee DB, no muta estado, no dispara eventos.
 *   - Stateless: solo el `$multiplier` inyectado (constante durante la app).
 *   - Idempotente: mismo input → mismo output.
 *   - Singleton-safe: registrado como tal en AppServiceProvider.
 *
 * Regla de negocio (igual que SaleTaxCalculator):
 *   1. Por cada línea Gravada15: `lineBase = lineTotal / multiplier`,
 *      `lineIsv = lineTotal - lineBase` (NUNCA `lineBase * rate` porque
 *      acumula error de redondeo y desplaza el total en centavos).
 *   2. Por cada línea Exenta: `lineBase = lineTotal`, `lineIsv = 0`.
 *   3. Sumar `subtotal`, `exemptTotal`, `taxableTotal`, `isv`.
 *   4. `total = round(subtotal + isv, 2)`.
 *
 * El servicio es agnóstico del `RepairItemSource` — solo le importa el
 * `TaxType` ya resuelto. La resolución (mano_obra → exento, pieza nueva →
 * gravado, pieza inventario → tax_type del Product) la hace el caller
 * (RepairQuotationService) ANTES de pasar las líneas aquí.
 */
final class RepairTaxCalculator
{
    public function __construct(
        private readonly float $multiplier = 1.15,
    ) {
        if ($multiplier <= 0) {
            throw new \InvalidArgumentException(
                "RepairTaxCalculator: multiplier debe ser > 0. Recibido: {$multiplier}"
            );
        }
    }

    /**
     * Calcular el desglose fiscal completo de una cotización.
     *
     * @param  RepairTaxableLine[]  $lines
     */
    public function calculate(array $lines): RepairTaxBreakdown
    {
        if (empty($lines)) {
            return RepairTaxBreakdown::empty();
        }

        $subtotal = 0.0;
        $exemptTotal = 0.0;
        $taxableTotal = 0.0;
        $totalIsv = 0.0;
        $grossTotal = 0.0;
        $lineBreakdowns = [];

        foreach ($lines as $line) {
            if (! $line instanceof RepairTaxableLine) {
                throw new \InvalidArgumentException(
                    'RepairTaxCalculator::calculate() espera array<RepairTaxableLine>.'
                );
            }

            $lineTotal = $line->lineTotal();

            if ($line->taxType === TaxType::Gravado15) {
                $lineBase = round($lineTotal / $this->multiplier, 2);
                $lineIsv = round($lineTotal - $lineBase, 2);
                $taxableTotal += $lineBase;
            } else {
                $lineBase = $lineTotal;
                $lineIsv = 0.0;
                $exemptTotal += $lineBase;
            }

            $lineBreakdowns[] = new RepairLineBreakdown(
                identity: $line->identity,
                subtotal: $lineBase,
                isv: $lineIsv,
                total: $lineTotal,
            );

            $subtotal += $lineBase;
            $totalIsv += $lineIsv;
            $grossTotal += $lineTotal;
        }

        return new RepairTaxBreakdown(
            lines: $lineBreakdowns,
            subtotal: round($subtotal, 2),
            exemptTotal: round($exemptTotal, 2),
            taxableTotal: round($taxableTotal, 2),
            isv: round($totalIsv, 2),
            total: round($grossTotal, 2),
        );
    }
}

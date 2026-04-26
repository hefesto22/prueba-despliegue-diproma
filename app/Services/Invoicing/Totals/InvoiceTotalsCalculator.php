<?php

namespace App\Services\Invoicing\Totals;

use App\Enums\TaxType;
use App\Models\Sale;
use App\Services\Sales\Tax\SaleTaxCalculator;
use App\Services\Sales\Tax\TaxableLine;

/**
 * Calculador fiscal de Facturas (tipo 01 SAR).
 *
 * Extraído del método privado `InvoiceService::calculateFiscalBreakdown`
 * (refactor E.2.A4) para aplicar SRP + corregir bug fiscal crítico: la
 * versión previa persistía `invoices.taxable_total = Sale.subtotal`, donde
 * `Sale.subtotal` es la suma de bases gravadas + exentas (por convención
 * heredada de SaleTaxCalculator). Consecuencia: el Libro de Ventas SAR
 * reportaba como "Ventas Gravadas" la suma de gravado + exento para
 * cualquier factura con producto exento, inflando la base gravada en el
 * archivo entregado a DEI/SAR.
 *
 * ## Diseño simétrico al CreditNoteTotalsCalculator
 *
 *   - Delega el desglose per-línea al mismo {@see SaleTaxCalculator} que
 *     usa el POS y SaleService. Esto garantiza que POS, Venta, Factura y
 *     NC usen la MISMA regla de redondeo per-línea. Cambio fiscal (ej.
 *     SAR ajusta redondeo, nueva tasa dual) es un solo edit en
 *     SaleTaxCalculator y se propaga a todos los flujos fiscales.
 *
 *   - Segrega `taxable` vs `exempt` manualmente según `TaxType` del item
 *     — SaleTaxCalculator los suma en un solo `subtotal` por performance;
 *     la segregación de vive aquí.
 *
 *   - Aplica el ratio de descuento a los AGREGADOS (taxable, exempt, isv)
 *     usando la fórmula corregida `gross = Sale.total + Sale.discount_amount`.
 *     La fórmula previa `Sale.subtotal + Sale.isv + exempt_total`
 *     double-counteaba el exento porque Sale.subtotal ya lo incluía.
 *     Dormant bug: solo se activaba en facturas mix (gravado + exento)
 *     CON descuento — produciría un ratio menor al correcto y acreditaba
 *     de más. Las 4 facturas en producción (ID 2, 3, 9, 10) no tenían
 *     descuento y por eso nunca se manifestó en data, pero el cálculo
 *     ahora es correcto para cualquier futuro caso.
 *
 * ## Pure + stateless
 *
 * No lee DB, no persiste, no dispara eventos. Construye `TaxableLine[]`
 * desde los SaleItems cargados y delega todo el cálculo. Testeable sin
 * `RefreshDatabase` — solo requiere armar un Sale en memoria con items.
 *
 * ## Paridad con Sale
 *
 * El InvoiceTotalsResult devuelto cumple, para un Sale con items
 * coherentes (que es el contrato post-SaleService):
 *
 *   - `result.subtotal` ≈ `Sale.subtotal`  (mismo algoritmo, mismo input)
 *   - `result.isv`      ≈ `Sale.isv`
 *   - `result.total`    ≈ `Sale.total`
 *
 * No debe haber drift porque ambos caminos usan SaleTaxCalculator sobre
 * los mismos TaxableLines. Los tests de paridad verifican esto.
 *
 * @see \App\Services\Sales\Tax\SaleTaxCalculator  Fuente única del desglose per-línea.
 * @see \App\Services\CreditNotes\Totals\CreditNoteTotalsCalculator  Simétrico para NC.
 */
final class InvoiceTotalsCalculator
{
    public function __construct(
        private readonly SaleTaxCalculator $taxCalculator,
    ) {}

    /**
     * Calcular el desglose fiscal de una Factura desde su Sale origen.
     *
     * @param  Sale  $sale  Venta ya procesada. Si los items no están cargados
     *                      los carga (idempotente). Solo lectura — no muta
     *                      nada del Sale.
     */
    public function calculate(Sale $sale): InvoiceTotalsResult
    {
        if (! $sale->relationLoaded('items')) {
            $sale->load('items');
        }

        if ($sale->items->isEmpty()) {
            return new InvoiceTotalsResult(
                subtotal:     0.0,
                taxableTotal: 0.0,
                exemptTotal:  0.0,
                isv:          0.0,
                total:        0.0,
            );
        }

        // 1. Construir TaxableLines desde los SaleItems persistidos.
        //    Los items guardan precio nominal (CON ISV incluido) y tax_type
        //    por línea — mismo input que consumió SaleTaxCalculator al
        //    calcular los totales del Sale.
        $taxLines = [];
        foreach ($sale->items as $item) {
            $taxLines[] = new TaxableLine(
                unitPrice: (float) $item->unit_price,
                quantity:  (int) $item->quantity,
                taxType:   $item->tax_type,
                identity:  $item->id,
            );
        }

        // 2. Delegar desglose per-línea al SaleTaxCalculator. Pasamos
        //    discountAmount=0 porque distribuimos el descuento manualmente
        //    per-bucket después (taxable y exempt por separado), NO a las
        //    líneas individuales. Mismo patrón que CreditNoteTotalsCalculator.
        $breakdown = $this->taxCalculator->calculate($taxLines, discountAmount: 0.0);

        // 3. Segregar taxable vs exempt según TaxType del item origen.
        //    El orden posicional de $breakdown->lines coincide con el de
        //    $sale->items (SaleTaxCalculator preserva orden).
        $taxable = 0.0;
        $exempt  = 0.0;
        $isv     = 0.0;

        foreach ($sale->items as $i => $item) {
            $line = $breakdown->lines[$i];

            if ($item->tax_type === TaxType::Gravado15) {
                $taxable += $line->subtotal;
                $isv     += $line->isv;
            } else {
                $exempt  += $line->subtotal;
            }
        }

        // 4. Ratio de descuento derivado de campos persistidos sin ambigüedad.
        //    gross = Sale.total + Sale.discount_amount
        //          = (Sale.subtotal + Sale.isv) + Sale.discount_amount
        //    donde Sale.subtotal YA incluye el exento (convención heredada
        //    de SaleTaxCalculator). NO sumamos exempt aparte como hacía la
        //    versión buggy — eso double-counteaba.
        $discount = (float) ($sale->discount_amount ?? 0);
        $gross    = (float) $sale->total + $discount;
        $ratio    = ($gross > 0 && $discount > 0) ? $discount / $gross : 0.0;

        if ($ratio > 0) {
            $taxable = round($taxable * (1 - $ratio), 2);
            $exempt  = round($exempt  * (1 - $ratio), 2);
            $isv     = round($isv     * (1 - $ratio), 2);
        } else {
            // Normalización defensiva contra drift del accumulator
            // (suma de lineBases ya redondeados puede terminar en 0.00000001).
            $taxable = round($taxable, 2);
            $exempt  = round($exempt, 2);
            $isv     = round($isv, 2);
        }

        $subtotal = round($taxable + $exempt, 2);
        $total    = round($subtotal + $isv, 2);

        return new InvoiceTotalsResult(
            subtotal:     $subtotal,
            taxableTotal: $taxable,
            exemptTotal:  $exempt,
            isv:          $isv,
            total:        $total,
        );
    }
}

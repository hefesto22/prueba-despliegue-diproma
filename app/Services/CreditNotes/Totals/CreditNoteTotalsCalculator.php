<?php

namespace App\Services\CreditNotes\Totals;

use App\Enums\TaxType;
use App\Models\Invoice;
use App\Models\SaleItem;
use App\Services\CreditNotes\DTOs\LineaAcreditarInput;
use App\Services\Sales\Tax\SaleTaxCalculator;
use App\Services\Sales\Tax\TaxableLine;
use Illuminate\Support\Collection;

/**
 * Calculador fiscal de Notas de Crédito.
 *
 * Extraído del método privado `CreditNoteService::calcularTotales` (refactor
 * E.2.A3) para aplicar SRP: el servicio orquesta la emisión y este calculador
 * es el único responsable de la matemática fiscal de la NC.
 *
 * ## Responsabilidad única
 *
 * Dado una factura origen + líneas a acreditar + SaleItems cargados, produce
 * un {@see TotalsResult} listo para persistir. No abre transacciones, no lee
 * DB (todo viene por parámetro), no emite eventos. Idempotente y testeable
 * sin `RefreshDatabase`.
 *
 * ## Delegación al SaleTaxCalculator
 *
 * El desglose por línea (gravado vs exento, lineBase, lineIsv) se delega al
 * mismo `SaleTaxCalculator` que usa el POS y `SaleService`. Esto garantiza
 * que la regla de redondeo per-línea (`round(lineTotal/multiplier, 2)` y
 * restar para el ISV, evitando drift) sea idéntica en venta y en NC —
 * emitir NC de una factura sin descuento devuelve exactamente los mismos
 * subtotal/isv/total por línea que se calcularon al vender. Cambio de regla
 * (ej. SAR ajusta redondeo) es un solo edit en SaleTaxCalculator y se
 * propaga a todos los flujos fiscales.
 *
 * ## Aplicación del ratio de descuento
 *
 * El descuento proporcional de la factura origen se aplica SOLO a los
 * agregados (`taxableTotal`, `exemptTotal`, `isv`), nunca a los items
 * individuales. Los items preservan el valor nominal — mismo comportamiento
 * que el código previo al refactor, consistente con cómo `SaleTaxCalculator`
 * trata el descuento a nivel de venta.
 *
 * ## Fórmula del gross (corrección fiscal E.2.A3)
 *
 * Usamos `gross = invoice.total + invoice.discount` como denominador del
 * ratio. La fórmula previa `taxable_total + exempt_total + isv + discount`
 * double-counteaba el exempt: `taxable_total` (= `Sale.subtotal` =
 * `SaleTaxCalculator.subtotal`) ya suma tanto la base gravada COMO la base
 * exenta por convención. Sumar `exempt_total` aparte inflaba el gross y
 * producía un ratio menor al correcto en facturas mix gravado/exento con
 * descuento — resultado: NC acreditaba de más (valores fiscalmente
 * incorrectos en Libro de Ventas SAR y Form 210 ISV).
 *
 * La fórmula corregida deriva el gross de campos persistidos sin ambigüedad:
 *   `invoice.total = sum(bases) - discount + isv`
 *   `invoice.total + invoice.discount = sum(bases) + isv = gross`
 *
 * Bug dormant en facturas all-gravado o all-exento (coincidía por
 * coincidencia) — solo se activaba en mix + descuento. Capturado por
 * `CreditNoteServiceTest::test_paridad_mix_gravado_y_exento_con_descuento`.
 *
 * @see \App\Services\Sales\Tax\SaleTaxCalculator  Fuente única del desglose per-línea.
 */
final class CreditNoteTotalsCalculator
{
    public function __construct(
        private readonly SaleTaxCalculator $taxCalculator,
    ) {}

    /**
     * Calcular el desglose fiscal de una Nota de Crédito.
     *
     * @param  Invoice  $invoice  Factura origen ya cargada (lockForUpdate en el service).
     *                            Solo leemos `discount` y `total` — no tocamos Invoice
     *                            para nada más.
     * @param  list<LineaAcreditarInput>  $lineas  Líneas solicitadas por el usuario.
     *                                             Ya validadas por el service (pertenencia
     *                                             + acumulativo vs NCs previas).
     * @param  Collection<int, SaleItem>  $saleItems  SaleItems de la venta origen,
     *                                                indexados por id.
     *
     * @throws \InvalidArgumentException  Si algún `saleItemId` del input no está presente
     *                                     en `$saleItems`. Blindaje defensivo: en el flujo
     *                                     real el service ya validó esto con
     *                                     `assertCantidadesDisponibles`.
     */
    public function calculate(
        Invoice $invoice,
        array $lineas,
        Collection $saleItems,
    ): TotalsResult {
        if ($lineas === []) {
            return new TotalsResult(
                taxableTotal: 0.0,
                exemptTotal:  0.0,
                isv:          0.0,
                total:        0.0,
                items:        [],
            );
        }

        // 1. Construir TaxableLines usando sale_item_id como identity. El orden
        //    del array de entrada se preserva en breakdown->lines — mapeo
        //    posicional seguro, sin necesidad de lineFor().
        $taxLines = [];
        foreach ($lineas as $linea) {
            /** @var SaleItem|null $saleItem */
            $saleItem = $saleItems[$linea->saleItemId] ?? null;
            if ($saleItem === null) {
                throw new \InvalidArgumentException(
                    "CreditNoteTotalsCalculator: sale_item_id {$linea->saleItemId} "
                    . 'no está presente en la Collection de SaleItems — el service debe '
                    . 'cargarlos antes de invocar el calculador.'
                );
            }

            $taxLines[] = new TaxableLine(
                unitPrice: (float) $saleItem->unit_price,
                quantity:  $linea->quantity,
                taxType:   $saleItem->tax_type,
                identity:  $saleItem->id,
            );
        }

        // 2. Delegar desglose per-línea al SaleTaxCalculator. Pasamos
        //    discountAmount=0 porque el descuento de la NC NO se distribuye a
        //    las líneas — se deriva del ratio de la factura origen y se aplica
        //    solo a los agregados. Los items preservan los nominales para
        //    impresión fiel del documento.
        $breakdown = $this->taxCalculator->calculate($taxLines, discountAmount: 0.0);

        // 3. Agregar por tax_type (el schema de NC separa taxable_total y
        //    exempt_total aunque SaleTaxCalculator los sume en un solo
        //    subtotal) y construir items[] en la misma pasada.
        $taxable = 0.0;
        $exempt  = 0.0;
        $isv     = 0.0;
        $items   = [];

        foreach ($lineas as $i => $linea) {
            /** @var SaleItem $saleItem */
            $saleItem = $saleItems[$linea->saleItemId];
            $line     = $breakdown->lines[$i];

            if ($saleItem->tax_type === TaxType::Gravado15) {
                $taxable += $line->subtotal;
                $isv     += $line->isv;
            } else {
                $exempt  += $line->subtotal;
            }

            $items[] = [
                'sale_item_id' => $saleItem->id,
                'product_id'   => $saleItem->product_id,
                'quantity'     => $linea->quantity,
                'unit_price'   => $saleItem->unit_price,
                'tax_type'     => $saleItem->tax_type,
                'subtotal'     => $line->subtotal,
                'isv_amount'   => $line->isv,
                'total'        => $line->total,
            ];
        }

        // 4. Ratio de descuento de la factura origen. Gross derivado de los
        //    campos persistidos sin ambigüedad (ver PHPDoc de clase).
        $discount     = (float) $invoice->discount;
        $invoiceGross = (float) $invoice->total + $discount;
        $ratio        = ($invoiceGross > 0 && $discount > 0) ? $discount / $invoiceGross : 0.0;

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

        return new TotalsResult(
            taxableTotal: $taxable,
            exemptTotal:  $exempt,
            isv:          $isv,
            total:        round($taxable + $exempt + $isv, 2),
            items:        $items,
        );
    }
}

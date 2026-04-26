<?php

namespace App\Services\Invoicing\Totals;

/**
 * Value Object de salida del {@see InvoiceTotalsCalculator}.
 *
 * Espejo simétrico de {@see \App\Services\CreditNotes\Totals\TotalsResult}
 * adaptado al schema de `invoices`. Todos los valores monetarios vienen
 * redondeados a 2 decimales y listos para persistir en `invoices` sin
 * transformación adicional — el consumidor (InvoiceService) solo mapea
 * campos, no recalcula.
 *
 * ## Semántica de los campos (post refactor E.2.A4)
 *
 *   - `subtotal`     → Suma de bases (gravada + exenta) POST descuento.
 *                      Espejo de `Sale.subtotal` por consistencia del snapshot.
 *                      Lo consume InvoicePrintService como "Subtotal".
 *
 *   - `taxableTotal` → SOLO base gravada POST descuento.
 *                      Lo consumen SalesBookService / SalesBookEntry::fromInvoice
 *                      para reportar la columna "Ventas Gravadas" del Libro de
 *                      Ventas SAR. Antes del refactor este campo persistía
 *                      `Sale.subtotal` (gravado + exento), causando que el
 *                      Libro reportara mal la segregación gravado/exento en
 *                      cualquier factura con producto exento.
 *
 *   - `exemptTotal`  → SOLO base exenta POST descuento.
 *                      Reportada como "Ventas Exentas" en Libro SAR.
 *
 *   - `isv`          → ISV POST descuento (sin cambios semánticos).
 *
 *   - `total`        → subtotal + isv. Equivalente a Sale.total.
 *
 * ## Invariante
 *
 *   `taxableTotal + exemptTotal == subtotal`  (tolerancia 0.01 por redondeo)
 *
 * El calculator preserva esta igualdad aplicando el ratio de descuento
 * proporcionalmente a cada bucket (taxable, exempt) por separado, NO al
 * subtotal de modo agregado.
 *
 * Inmutable por construcción: readonly + final.
 */
final class InvoiceTotalsResult
{
    public function __construct(
        public readonly float $subtotal,
        public readonly float $taxableTotal,
        public readonly float $exemptTotal,
        public readonly float $isv,
        public readonly float $total,
    ) {}
}

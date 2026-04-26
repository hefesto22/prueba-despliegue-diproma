<?php

namespace App\Services\CreditNotes\Totals;

/**
 * Value Object de salida del {@see CreditNoteTotalsCalculator}.
 *
 * Todos los valores monetarios vienen redondeados a 2 decimales y listos para
 * persistir en `credit_notes` / `credit_note_items` sin transformación
 * adicional — el consumidor (CreditNoteService) solo mapea campos, no
 * recalcula.
 *
 * Segregación taxable vs exempt: el schema de NC mantiene dos columnas
 * separadas aunque `SaleTaxCalculator.subtotal` las sume en un único total
 * base. El calculador cumple esa segregación antes de devolver el DTO.
 *
 * `items` preserva el shape histórico de `CreditNoteItem::create` y conserva
 * los valores nominales per-línea (sin ratio de descuento) para impresión
 * fiel del documento — mismo comportamiento que tenía el método privado
 * previo al refactor E.2.A3.
 *
 * Inmutable por construcción: readonly + final.
 */
final class TotalsResult
{
    /**
     * @param  float  $taxableTotal   Base gravada agregada POST descuento, 2 decimales.
     * @param  float  $exemptTotal    Base exenta agregada POST descuento, 2 decimales.
     * @param  float  $isv            ISV agregado POST descuento, 2 decimales.
     * @param  float  $total          Suma (taxableTotal + exemptTotal + isv), 2 decimales.
     * @param  array<int, array<string, mixed>>  $items  Shape listo para
     *   `CreditNoteItem::create`. Llaves: sale_item_id, product_id, quantity,
     *   unit_price, tax_type, subtotal, isv_amount, total. Los montos
     *   per-línea NO aplican ratio — son el nominal de la venta, preservando
     *   el comportamiento previo (el ticket/factura muestra el tachado y el
     *   descuento como línea separada cuando aplica).
     */
    public function __construct(
        public readonly float $taxableTotal,
        public readonly float $exemptTotal,
        public readonly float $isv,
        public readonly float $total,
        public readonly array $items,
    ) {}
}

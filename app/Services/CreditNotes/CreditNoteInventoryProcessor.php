<?php

namespace App\Services\CreditNotes;

use App\Enums\MovementType;
use App\Models\CreditNote;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Services\CreditNotes\DTOs\LineaAcreditarInput;
use App\Services\CreditNotes\Exceptions\StockInsuficienteParaAnularNCException;
use Illuminate\Support\Collection;

/**
 * Orquestador de kardex para Notas de Crédito.
 *
 * Extraído de `CreditNoteService` (refactor E.2.A3) para aplicar SRP: el
 * service se encarga de la emisión/anulación fiscal, este procesador
 * encapsula TODA la interacción con inventario relacionada con NC.
 *
 * ## Contrato transaccional
 *
 * Ningún método abre `DB::transaction()`. Ambos asumen que el caller ya está
 * dentro de una transacción — así el failover de stock insuficiente o un
 * `lockForUpdate` bloqueado revierte la emisión/anulación completa, no solo
 * el paso de inventario. `CreditNoteService` es el único punto que abre
 * transacción, garantizando atomicidad end-to-end.
 *
 * ## Costo histórico preservado
 *
 * Tanto al emitir como al anular, el `unit_cost` de cada movimiento se reusa
 * del movimiento original (SalidaVenta o EntradaNotaCredito respectivamente)
 * — nunca del `Product.cost_price` actual, que puede haber cambiado entre
 * la venta y la NC, o entre la NC y su anulación. Esta regla protege la
 * integridad del kardex y del margen reportado en Libros SAR.
 *
 * Null unit_cost solo en facturas/NCs pre-migración — mismo comportamiento
 * tolerado que `SaleService::cancel`.
 *
 * ## Consolidación por product_id
 *
 * Dos líneas distintas del mismo producto se agregan en un único
 * `InventoryMovement`. Evita N movimientos donde 1 basta y simplifica
 * auditorías del kardex (1 SKU ↔ 1 movimiento por evento).
 *
 * ## Stateless
 *
 * Sin estado, sin dependencias inyectadas. Se registra por auto-wiring (o
 * `app()` interno) — no requiere binding explícito en el ServiceProvider.
 */
final class CreditNoteInventoryProcessor
{
    /**
     * Registrar EntradaNotaCredito al emitir una NC con devolución física.
     *
     * Invocado por `CreditNoteService::generateFromInvoice` dentro de su
     * transacción, SOLO cuando `CreditNoteReason::returnsToInventory()` es
     * true (razones no físicas como `ErrorFacturacion` o `AjustePrecio` no
     * tocan kardex al emitir, así que no hay nada que registrar).
     *
     * @param  Invoice     $invoice    Factura origen — usada para referenciar el
     *                                 SalidaVenta original del que reusamos unit_cost.
     * @param  CreditNote  $creditNote NC ya creada (filas en credit_notes/
     *                                 credit_note_items persistidas) pero aún
     *                                 sin emitted_at/integrity_hash.
     * @param  list<LineaAcreditarInput>  $lineas
     * @param  Collection<int, \App\Models\SaleItem>  $saleItems
     */
    public function registerReturn(
        Invoice $invoice,
        CreditNote $creditNote,
        array $lineas,
        Collection $saleItems,
    ): void {
        // Lookup O(1) de movimientos originales de salida por product_id.
        // Mismo patrón que SaleService::cancel — una query, consulta por id
        // después. No usamos with() porque InventoryMovement no es Eloquent
        // child de Sale.
        $originalMovements = InventoryMovement::query()
            ->where('reference_type', Sale::class)
            ->where('reference_id', $invoice->sale_id)
            ->where('type', MovementType::SalidaVenta)
            ->get()
            ->keyBy('product_id');

        // Consolidar cantidades por producto: dos líneas del mismo producto
        // (ej. mismo item en dos SaleItems, o el producto configurado en
        // varias variantes internas) se agregan en un único movimiento.
        $quantityByProduct = [];
        foreach ($lineas as $linea) {
            /** @var \App\Models\SaleItem $saleItem */
            $saleItem  = $saleItems[$linea->saleItemId];
            $productId = $saleItem->product_id;
            $quantityByProduct[$productId] = ($quantityByProduct[$productId] ?? 0) + $linea->quantity;
        }

        foreach ($quantityByProduct as $productId => $quantity) {
            /** @var Product $product */
            $product = Product::where('id', $productId)
                ->lockForUpdate()
                ->firstOrFail();

            // Reusar el unit_cost del SalidaVenta original — no cost_price
            // actual. Si la NC es pre-migración (movimiento original sin
            // unit_cost registrado), pasamos null y `InventoryMovement::record`
            // lo tolera (se traga el costo en 0/null en vez de petar).
            $originalUnitCost = $originalMovements[$productId]?->unit_cost;

            InventoryMovement::record(
                product:  $product,
                type:     MovementType::EntradaNotaCredito,
                quantity: $quantity,
                reference: $creditNote,
                notes:    "NC {$creditNote->credit_note_number} sobre factura {$invoice->invoice_number}",
                unitCost: $originalUnitCost !== null ? (float) $originalUnitCost : null,
                establishment: $creditNote->establishment,
            );

            $product->update(['stock' => $product->stock + $quantity]);
        }
    }

    /**
     * Registrar SalidaAnulacionNotaCredito al anular una NC que había
     * incrementado inventario.
     *
     * Invocado por `CreditNoteService::voidNotaCredito` dentro de su
     * transacción, SOLO cuando la razón original devolvió stock
     * (`CreditNoteReason::returnsToInventory()` = true al momento de emitir).
     *
     * Bloquea la anulación si el stock actual no alcanza para revertir la
     * entrada — típicamente porque la mercadería devuelta ya fue revendida.
     * En ese caso el usuario debe resolver el caso manualmente (nota de
     * débito, ajuste explícito de inventario, o compra de reposición) en vez
     * de permitir stock negativo silencioso que descuadraría el Libro de
     * Ventas y la valoración contable.
     *
     * @throws StockInsuficienteParaAnularNCException  Stock actual < cantidad
     *   a revertir. El caller (`voidNotaCredito`) deja propagar y la
     *   transacción externa hace rollback — el flag `is_void` no se
     *   actualiza y la NC sigue emitida.
     */
    public function revertForVoid(CreditNote $creditNote): void
    {
        // Precargar items+product — evita N+1 en el loop de consolidación.
        // Idempotente si ya están cargados gracias a loadMissing.
        $creditNote->loadMissing('items.product');

        // Lookup O(1) de movimientos de entrada originales por product_id.
        $originalMovements = InventoryMovement::query()
            ->where('reference_type', CreditNote::class)
            ->where('reference_id', $creditNote->id)
            ->where('type', MovementType::EntradaNotaCredito)
            ->get()
            ->keyBy('product_id');

        // Consolidar cantidades por producto (dos líneas del mismo producto
        // en la NC → un solo movimiento SalidaAnulacionNotaCredito).
        $quantityByProduct = [];
        foreach ($creditNote->items as $item) {
            $quantityByProduct[$item->product_id] =
                ($quantityByProduct[$item->product_id] ?? 0) + (int) $item->quantity;
        }

        foreach ($quantityByProduct as $productId => $quantity) {
            /** @var Product $product */
            $product = Product::where('id', $productId)
                ->lockForUpdate()
                ->firstOrFail();

            // Validar stock suficiente DENTRO del lock. El check y el
            // decrement van atómicamente — sin ventana entre lectura y
            // escritura donde otra transacción podría consumir el stock.
            if ($product->stock < $quantity) {
                throw new StockInsuficienteParaAnularNCException(
                    creditNoteId:     $creditNote->id,
                    creditNoteNumber: $creditNote->credit_note_number,
                    productId:        $product->id,
                    productName:      $product->name,
                    requerido:        $quantity,
                    disponible:       (int) $product->stock,
                );
            }

            $originalUnitCost = $originalMovements[$productId]?->unit_cost;

            InventoryMovement::record(
                product:  $product,
                type:     MovementType::SalidaAnulacionNotaCredito,
                quantity: $quantity,
                reference: $creditNote,
                notes:    "NC {$creditNote->credit_note_number} anulada",
                unitCost: $originalUnitCost !== null ? (float) $originalUnitCost : null,
                establishment: $creditNote->establishment,
            );

            $product->update(['stock' => $product->stock - $quantity]);
        }
    }
}

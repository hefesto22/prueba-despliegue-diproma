<?php

namespace App\Services\Sales;

use App\Enums\MovementType;
use App\Enums\TaxType;
use App\Models\Establishment;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;

/**
 * Procesa el impacto de una venta sobre el inventario: creación de SaleItems,
 * lock de productos, validación de stock, asentamientos de kardex y ajuste
 * de stock físico. Tanto para el flujo "procesar venta" como para "anular
 * venta previamente completada".
 *
 * E.2.A2 — Extraído de `SaleService::processSale()` y `SaleService::cancel()`
 * para cumplir SRP: `SaleService` orquesta la venta (cliente, totales, caja,
 * estado). Este processor se enfoca únicamente en inventario + kardex.
 *
 * ─── Contrato de transacción ─────────────────────────────────────────────
 * Los dos métodos públicos DEBEN invocarse desde dentro de una transacción
 * activa del caller. Si no lo están:
 *   - El `lockForUpdate` sobre Product no tiene efecto semántico (dos ventas
 *     concurrentes podrían leer el mismo stock y ambas "pasar" la validación).
 *   - Un fallo parcial (stock decrementa pero kardex falla) corrompería el
 *     inventario de manera silenciosa.
 *
 * No hay verificación runtime de `DB::transactionLevel() > 0` porque sería
 * frágil (depende de driver/extensión) y añade overhead en el hot path del
 * POS. La responsabilidad es del caller — documentada en el PHPDoc de cada
 * método.
 *
 * ─── Por qué no devuelve valores ─────────────────────────────────────────
 * Siguiendo la regla CQRS light del proyecto: estos son comandos. Modifican
 * estado y no retornan datos. El caller puede releer lo que necesite vía
 * `$sale->refresh()` o `$sale->load('items')`.
 */
class SaleInventoryProcessor
{
    /**
     * Procesa los items del carrito para una venta pendiente:
     *   1. Lock pesimista de cada producto (serializa ventas concurrentes
     *      sobre el mismo SKU).
     *   2. Valida stock suficiente. Fail-fast con `\RuntimeException` si no.
     *   3. Crea el `SaleItem` correspondiente (sin totales fiscales — eso lo
     *      resuelve `SaleService::calculateTotals` después de este paso).
     *   4. Captura el `cost_price` actual del producto como snapshot del
     *      costo promedio ponderado al momento de la venta. Se usa para
     *      valorización histórica y cálculo de ganancia bruta en reportes.
     *   5. Registra `InventoryMovement::SalidaVenta` con el costo snapshot.
     *   6. Decrementa `Product.stock`.
     *
     * @param  array<int, array{product_id: int, quantity: int|string, unit_price: float|string, tax_type?: string|TaxType}>  $cartItems
     *
     * @throws \RuntimeException Si falta stock para algún producto.
     */
    public function processCartItems(Sale $sale, array $cartItems, Establishment $establishment): void
    {
        foreach ($cartItems as $item) {
            $product = Product::where('id', $item['product_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $quantity = (int) $item['quantity'];

            if ($product->stock < $quantity) {
                throw new \RuntimeException(
                    "Stock insuficiente para '{$product->name}'. "
                    ."Disponible: {$product->stock}, Solicitado: {$quantity}."
                );
            }

            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $item['unit_price'],
                'tax_type' => $this->resolveTaxType($item, $product),
            ]);

            // Snapshot del CPP actual ANTES del decremento. Este valor se
            // congela en el kardex — nunca se recalcula aunque el cost_price
            // del producto cambie después por nuevas compras.
            $unitCostAtSale = (float) $product->cost_price;

            InventoryMovement::record(
                product: $product,
                type: MovementType::SalidaVenta,
                quantity: $quantity,
                reference: $sale,
                notes: "Venta {$sale->sale_number}",
                unitCost: $unitCostAtSale,
                establishment: $establishment,
            );

            $product->update([
                'stock' => $product->stock - $quantity,
            ]);
        }
    }

    /**
     * Revierte el impacto de inventario de una venta previamente completada.
     *
     * Preserva el costo histórico: lee los movimientos originales de
     * `SalidaVenta` en UNA query keyed por `product_id` y reutiliza el
     * `unit_cost` que se capturó al momento de la venta. Si se usara el
     * `cost_price` actual del producto, una reversión hecha después de un
     * cambio de CPP corrompería la valorización histórica del kardex.
     *
     *   1. Load items + movimientos originales indexados por product_id.
     *   2. Para cada item: lock producto, registrar `EntradaAnulacionVenta`
     *      con el costo histórico, incrementar stock.
     *
     * Invariante: `$sale` debe estar cargada con la relación `items`
     * (`$sale->load('items')`) antes de invocar. El processor no lo hace
     * implícitamente porque eso sería una query oculta — preferimos que el
     * caller controle explícitamente su eager loading.
     */
    public function revertForCancellation(Sale $sale, Establishment $establishment): void
    {
        // Una query, lookup O(1) por product_id en el loop. Preserva el
        // costo histórico del movimiento de salida original — no el
        // cost_price actual del producto, que puede haber cambiado.
        $originalMovements = InventoryMovement::query()
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->where('type', MovementType::SalidaVenta)
            ->get()
            ->keyBy('product_id');

        foreach ($sale->items as $item) {
            $product = Product::where('id', $item->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Reusar el costo histórico. Null solo si la venta es
            // pre-migración de unit_cost (datos legacy).
            $originalUnitCost = $originalMovements[$item->product_id]?->unit_cost;

            InventoryMovement::record(
                product: $product,
                type: MovementType::EntradaAnulacionVenta,
                quantity: $item->quantity,
                reference: $sale,
                notes: "Venta {$sale->sale_number} anulada",
                unitCost: $originalUnitCost !== null ? (float) $originalUnitCost : null,
                establishment: $establishment,
            );

            $product->update([
                'stock' => $product->stock + $item->quantity,
            ]);
        }
    }

    /**
     * Resuelve el TaxType del item: acepta string (del POS vía formulario),
     * enum (del SaleService programático) o cae al default del producto.
     */
    private function resolveTaxType(array $item, Product $product): string
    {
        $raw = $item['tax_type'] ?? null;

        if ($raw instanceof TaxType) {
            return $raw->value;
        }

        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        return $product->tax_type->value;
    }
}

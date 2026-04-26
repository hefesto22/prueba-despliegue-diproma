<?php

namespace App\Services\Purchases;

use App\Enums\MovementType;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function __construct(
        private readonly PurchaseTotalsCalculator $calculator,
    ) {}


    /**
     * Confirmar una compra: actualizar stock y costo promedio ponderado.
     *
     * Operación financiera crítica:
     * - Transacción DB con lockForUpdate() en cada producto
     * - El stock se incrementa con la cantidad comprada
     * - El costo del producto se actualiza al promedio ponderado:
     *   nuevo_costo = (stock_actual * costo_actual + qty_compra * costo_compra) / (stock_actual + qty_compra)
     *
     * El costo se maneja CON ISV incluido (mismo formato que product.cost_price).
     *
     * @throws \InvalidArgumentException Si la compra no está en estado borrador
     * @throws \RuntimeException Si la compra no tiene items
     */
    public function confirm(Purchase $purchase): void
    {
        if (! $purchase->status->canConfirm()) {
            throw new \InvalidArgumentException(
                "No se puede confirmar una compra en estado '{$purchase->status->getLabel()}'."
            );
        }

        // Cargar establishment para atribuir el kardex a la sucursal de la compra.
        $purchase->load(['items', 'establishment']);

        if ($purchase->items->isEmpty()) {
            throw new \RuntimeException('No se puede confirmar una compra sin items.');
        }

        DB::transaction(function () use ($purchase) {
            // 1. Recalcular totales por si los items cambiaron
            $this->calculator->recalculate($purchase);

            // 2. Actualizar stock y costo promedio de cada producto
            foreach ($purchase->items as $item) {
                $product = Product::where('id', $item->product_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Registrar movimiento ANTES de actualizar stock
                // (record() usa product.stock actual como stock_before y calcula stock_after)
                // unit_cost: el costo real de esta compra (con ISV para gravados)
                InventoryMovement::record(
                    product: $product,
                    type: MovementType::EntradaCompra,
                    quantity: $item->quantity,
                    reference: $purchase,
                    notes: "Compra {$purchase->purchase_number} confirmada",
                    unitCost: (float) $item->unit_cost,
                    establishment: $purchase->establishment,
                );

                $this->updateProductCostAndStock($product, $item->quantity, $item->unit_cost);
            }

            // 3. Cambiar estado y resolver payment_status según condiciones de pago.
            //
            // Compra al contado (credit_days = 0): el pago se ejecuta al recibir
            // la mercancía → marcamos Pagada en la misma transacción donde se
            // afectó el stock. Atomicidad: si algo falla en stock o costo, también
            // se reversa el cambio de estado de pago.
            //
            // Compra a crédito (credit_days > 0): queda Pendiente hasta que el
            // módulo de Cuentas por Pagar (CxP) registre el pago. Hoy ese módulo
            // está pausado, pero respetamos la regla para que cuando se construya
            // CxP no haya que tocar este Service de nuevo.
            $updates = ['status' => PurchaseStatus::Confirmada];

            if ((int) $purchase->credit_days === 0) {
                $updates['payment_status'] = PaymentStatus::Pagada;
            }

            $purchase->update($updates);
        });
    }

    /**
     * Anular una compra.
     * Si estaba confirmada, reversa el stock (pero NO el costo promedio).
     *
     * Razón de no reversar costo: el costo promedio ponderado es acumulativo.
     * Revertirlo requeriría recalcular desde el historial completo de compras,
     * lo cual es complejo y propenso a errores. El costo se corregirá
     * naturalmente con las siguientes compras.
     *
     * @throws \InvalidArgumentException Si la compra ya está anulada
     */
    public function cancel(Purchase $purchase): void
    {
        if (! $purchase->status->canCancel()) {
            throw new \InvalidArgumentException('Esta compra ya está anulada.');
        }

        $wasConfirmed = $purchase->status === PurchaseStatus::Confirmada;

        DB::transaction(function () use ($purchase, $wasConfirmed) {
            if ($wasConfirmed) {
                // Cargar establishment para atribuir la reversa de kardex a la
                // sucursal donde se registró originalmente la compra.
                $purchase->load(['items', 'establishment']);

                foreach ($purchase->items as $item) {
                    $product = Product::where('id', $item->product_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    // Registrar movimiento de salida ANTES de reversar stock
                    // unit_cost: mismo costo con que entró esta línea — reversa exacta
                    InventoryMovement::record(
                        product: $product,
                        type: MovementType::SalidaAnulacionCompra,
                        quantity: $item->quantity,
                        reference: $purchase,
                        notes: "Compra {$purchase->purchase_number} anulada",
                        unitCost: (float) $item->unit_cost,
                        establishment: $purchase->establishment,
                    );

                    // Reversar stock (nunca dejar negativo)
                    $newStock = max(0, $product->stock - $item->quantity);
                    $product->update(['stock' => $newStock]);
                }
            }

            $purchase->update(['status' => PurchaseStatus::Anulada]);
        });
    }

    /**
     * Actualizar costo promedio ponderado y stock de un producto.
     *
     * Fórmula: nuevo_costo = (stock_actual * costo_actual + qty_nueva * costo_nuevo) / (stock_actual + qty_nueva)
     *
     * Caso especial: si el stock actual es 0, el nuevo costo es simplemente el costo de la compra.
     *
     * @param Product $product     Producto con lock
     * @param int     $quantity    Cantidad comprada
     * @param float   $unitCost   Costo unitario CON ISV (tal como lo ingresó el usuario)
     */
    private function updateProductCostAndStock(Product $product, int $quantity, float $unitCost): void
    {
        $currentStock = (int) $product->stock;
        $currentCost = (float) $product->cost_price;

        if ($currentStock <= 0) {
            // Sin stock previo: el nuevo costo es el de esta compra
            $newCost = $unitCost;
        } else {
            // Promedio ponderado
            $totalCurrentValue = $currentStock * $currentCost;
            $totalNewValue = $quantity * $unitCost;
            $newCost = ($totalCurrentValue + $totalNewValue) / ($currentStock + $quantity);
        }

        $product->update([
            'cost_price' => round($newCost, 2),
            'stock' => $currentStock + $quantity,
        ]);
    }

}

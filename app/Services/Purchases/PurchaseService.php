<?php

namespace App\Services\Purchases;

use App\Enums\MovementType;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Enums\TaxType;
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
     * ─── Convención de costos (FUENTE ÚNICA DE VERDAD) ──────────────────────
     * `Product.cost_price` es SIEMPRE el costo NETO (sin ISV) en libros.
     * `PurchaseItem.unit_cost` es el costo TAL COMO LO INGRESÓ EL OPERADOR:
     *   - En Factura + producto Gravado15: viene CON ISV incluido — hay que
     *     hacer back-out antes de promediar contra cost_price.
     *   - En Recibo Interno: viene como precio final sin desglose fiscal —
     *     no hay back-out, el unit_cost ES el costo neto efectivo.
     *   - En producto Exento (Usado, servicios): no hay ISV que separar —
     *     unit_cost es directamente el costo neto.
     *
     * El crédito fiscal (Factura + Gravado15) vive en `purchases.isv` como
     * activo tributario separado, NO se mezcla con el costo del inventario.
     *
     * ─── CPP móvil (Costo Promedio Ponderado) ───────────────────────────────
     * nuevo_cost_NETO = (stock_actual × cost_price + qty × netUnitCost) / (stock_actual + qty)
     *
     * Operación financiera crítica:
     *   - Transacción DB con lockForUpdate() en cada producto
     *   - Stock se incrementa con la cantidad comprada
     *   - Kardex captura el unit_cost NETO (consistente con SaleInventoryProcessor)
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

                // Derivar unit_cost NETO según tipo de documento + tax_type del item.
                // Esto es lo que entra al kardex (snapshot) y al CPP del producto —
                // garantiza que cost_price siempre quede en NETO sin importar si la
                // compra fue Factura (con ISV en unit_cost) o RI (sin ISV).
                $netUnitCost = static::netUnitCost(
                    rawUnitCost: (float) $item->unit_cost,
                    taxType: $item->tax_type,
                    documentType: $purchase->document_type,
                );

                // Registrar movimiento ANTES de actualizar stock — record() usa
                // product.stock actual como stock_before y calcula stock_after.
                // unit_cost del kardex = NETO, igual que el snapshot que captura
                // SaleInventoryProcessor para ventas. Esto permite que reportes
                // de COGS y dashboard de ganancia bruta se sumen sin mezclar
                // unidades fiscales con unidades de costo.
                InventoryMovement::record(
                    product: $product,
                    type: MovementType::EntradaCompra,
                    quantity: $item->quantity,
                    reference: $purchase,
                    notes: "Compra {$purchase->purchase_number} confirmada",
                    unitCost: $netUnitCost,
                    establishment: $purchase->establishment,
                );

                $this->updateProductCostAndStock($product, $item->quantity, $netUnitCost);
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

                    // Reversa simétrica: el unit_cost de la salida de anulación
                    // debe ser idéntico al unit_cost de la entrada original — así
                    // entrada + salida cuadran a cero en el kardex (NETO).
                    $netUnitCost = static::netUnitCost(
                        rawUnitCost: (float) $item->unit_cost,
                        taxType: $item->tax_type,
                        documentType: $purchase->document_type,
                    );

                    InventoryMovement::record(
                        product: $product,
                        type: MovementType::SalidaAnulacionCompra,
                        quantity: $item->quantity,
                        reference: $purchase,
                        notes: "Compra {$purchase->purchase_number} anulada",
                        unitCost: $netUnitCost,
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
     * Fórmula CPP móvil (NETO):
     *   nuevo_cost = (stock_actual × cost_actual + qty × netUnitCost) / (stock_actual + qty)
     *
     * Caso especial: si el stock actual es 0, el nuevo costo es directamente
     * el netUnitCost de esta compra (no hay costo previo que promediar).
     *
     * @param Product $product       Producto con lock pesimista activo.
     * @param int     $quantity      Cantidad comprada.
     * @param float   $netUnitCost   Costo unitario NETO (sin ISV) — ya con back-out
     *                               aplicado si correspondía. Mismo valor que se
     *                               capturó en kardex como snapshot.
     */
    private function updateProductCostAndStock(Product $product, int $quantity, float $netUnitCost): void
    {
        $currentStock = (int) $product->stock;
        $currentCost = (float) $product->cost_price;

        if ($currentStock <= 0) {
            // Sin stock previo: el nuevo costo es directamente el de esta compra.
            $newCost = $netUnitCost;
        } else {
            // Promedio ponderado: pondera unidades existentes (a su CPP histórico)
            // con las unidades nuevas (al netUnitCost de esta compra).
            $totalCurrentValue = $currentStock * $currentCost;
            $totalNewValue = $quantity * $netUnitCost;
            $newCost = ($totalCurrentValue + $totalNewValue) / ($currentStock + $quantity);
        }

        $product->update([
            'cost_price' => round($newCost, 2),
            'stock' => $currentStock + $quantity,
        ]);
    }

    /**
     * Derivar el costo unitario NETO (sin ISV) según tipo de documento y tax_type.
     *
     * Reglas:
     *   - Factura + Gravado15 → back-out: NETO = rawUnitCost / 1.15
     *   - Recibo Interno (cualquier tax) → sin back-out: NETO = rawUnitCost
     *   - Cualquier doc + Exento → sin back-out: NETO = rawUnitCost
     *
     * El multiplicador 1.15 viene de config/tax.php (TASA_ISV_HONDURAS) para
     * que un cambio futuro de tasa solo toque un archivo.
     *
     * Es estática para que pueda llamarse sin instanciar el servicio (útil en
     * tests y en cualquier flujo futuro que necesite la misma derivación).
     */
    public static function netUnitCost(
        float $rawUnitCost,
        ?TaxType $taxType,
        ?SupplierDocumentType $documentType,
    ): float {
        $separates = ($documentType?->separatesIsv() ?? true)
            && $taxType === TaxType::Gravado15;

        if (! $separates) {
            return round($rawUnitCost, 2);
        }

        $multiplier = (float) config('tax.multiplier', 1.15);

        return round($rawUnitCost / $multiplier, 2);
    }
}

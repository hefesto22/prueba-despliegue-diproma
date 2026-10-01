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
use App\Services\Purchases\Exceptions\CompraConProductosHeredadaException;
use Illuminate\Support\Facades\DB;

/**
 * Transiciones de estado de una compra (confirmar / anular).
 *
 * ─── Compras = documento fiscal puro (Fase 1, rediseño 2026-07-25) ──────────
 * Una compra ya no tiene líneas de producto: registra el documento del
 * proveedor (tipo, número, CAI, montos) para el Libro de Compras y el crédito
 * fiscal. El inventario entra por la ficha del producto (ProductStockLedger),
 * no por aquí. Por eso confirmar NO toca stock, costo ni Kardex.
 *
 * ─── Compras heredadas (con `purchase_items`) ───────────────────────────────
 * Las compras capturadas antes de la Fase 1 conservan sus líneas como
 * histórico. Dos reglas las protegen:
 *   - Un BORRADOR heredado con líneas no se puede confirmar
 *     (CompraConProductosHeredadaException): confirmarlo ya no metería stock.
 *   - Una CONFIRMADA heredada que se anula sigue revirtiendo su stock, porque
 *     esas unidades sí entraron al inventario cuando se confirmó.
 *
 * ─── Compras NO mueven la caja (decisión 2026-09-29) ───────────────────────
 * Registrar una compra o un gasto es solo registrar el documento. La forma de
 * pago es informativa: confirmar o anular nunca crea movimientos de caja.
 * Los gastos migrados del módulo Gastos conservan su salida de caja histórica
 * enlazada (Purchase::cashMovements), pero anularlos no la revierte.
 *
 * ─── Concurrencia ───────────────────────────────────────────────────────────
 * Ambas transiciones releen la compra con `lockForUpdate()` y validan el
 * estado DENTRO de la transacción. Sin el lock, dos anulaciones simultáneas de
 * una compra heredada (doble clic, dos pestañas) pasaban la validación antes
 * de que la otra escribiera y revertían el stock dos veces.
 */
class PurchaseService
{
    /**
     * Confirmar una compra: el documento pasa a formar parte del Libro de
     * Compras (salvo Recibo Interno) y, si es de contado, queda Pagada.
     *
     * @throws \InvalidArgumentException Si la compra no está en Borrador.
     * @throws CompraConProductosHeredadaException Si es un borrador heredado con líneas de producto.
     */
    public function confirm(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            $locked = $this->lockForTransition($purchase);

            if (! $locked->status->canConfirm()) {
                throw new \InvalidArgumentException(
                    "No se puede confirmar una compra en estado '{$locked->status->getLabel()}'."
                );
            }

            if ($locked->items()->exists()) {
                throw new CompraConProductosHeredadaException($locked->purchase_number);
            }

            // Contado (credit_days = 0): el pago se da por ejecutado al
            // confirmar el documento. Crédito: queda Pendiente hasta que exista
            // el módulo de Cuentas por Pagar — se respeta la regla para no
            // tener que tocar este método cuando se construya.
            $updates = ['status' => PurchaseStatus::Confirmada];

            if ((int) $locked->credit_days === 0) {
                $updates['payment_status'] = PaymentStatus::Pagada;
            }

            $locked->update($updates);
        });

        $purchase->refresh();
    }

    /**
     * Anular una compra.
     *
     * Para compras del modelo actual (sin líneas) solo cambia el estado.
     * Para compras heredadas confirmadas revierte el stock que entraron — pero
     * NO el costo promedio que calcularon en su momento: el CPP era acumulativo
     * y reconstruirlo exigiría recalcular todo el historial del producto.
     *
     * El payment_status no se toca: queda como histórico de que se pagó.
     *
     * @throws \InvalidArgumentException Si la compra ya está anulada.
     */
    public function cancel(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            $locked = $this->lockForTransition($purchase);

            if (! $locked->status->canCancel()) {
                throw new \InvalidArgumentException('Esta compra ya está anulada.');
            }

            if ($locked->status === PurchaseStatus::Confirmada) {
                $this->reverseLegacyStock($locked);
            }

            $locked->update(['status' => PurchaseStatus::Anulada]);
        });

        $purchase->refresh();
    }

    /**
     * Derivar el costo unitario NETO (sin ISV) de una línea heredada.
     *
     * Solo lo usa la reversa de compras heredadas: la salida de anulación debe
     * registrar en el Kardex exactamente el mismo costo NETO que registró la
     * entrada al confirmarse, para que entrada + salida cuadren a cero.
     *
     * Reglas (idénticas a las que aplicaba la confirmación con líneas):
     *   - Factura + Gravado15 → back-out: NETO = rawUnitCost / 1.15
     *   - Recibo Interno (cualquier tax) → sin back-out
     *   - Cualquier documento + Exento → sin back-out
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

    /**
     * Releer la compra con lock pesimista para validar y escribir su estado
     * sin que otra transacción se cuele entre la lectura y el UPDATE.
     */
    private function lockForTransition(Purchase $purchase): Purchase
    {
        return Purchase::query()
            ->whereKey($purchase->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Sacar del inventario las unidades que una compra heredada metió al
     * confirmarse. Sin líneas (compras del modelo actual) no hace nada.
     */
    private function reverseLegacyStock(Purchase $purchase): void
    {
        // establishment: la reversa se atribuye a la sucursal donde entró el stock.
        $purchase->load(['items', 'establishment']);

        foreach ($purchase->items as $item) {
            $product = Product::query()
                ->whereKey($item->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            InventoryMovement::record(
                product: $product,
                type: MovementType::SalidaAnulacionCompra,
                quantity: $item->quantity,
                reference: $purchase,
                notes: "Compra {$purchase->purchase_number} anulada",
                unitCost: static::netUnitCost(
                    rawUnitCost: (float) $item->unit_cost,
                    taxType: $item->tax_type,
                    documentType: $purchase->document_type,
                ),
                establishment: $purchase->establishment,
            );

            // Nunca dejar stock negativo: parte de esas unidades ya pudo venderse.
            $product->update(['stock' => max(0, $product->stock - $item->quantity)]);
        }
    }
}

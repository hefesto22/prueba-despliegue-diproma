<?php

namespace App\Services\Purchases;

use App\Enums\SupplierDocumentType;
use App\Enums\TaxType;
use App\Models\Purchase;
use App\Models\PurchaseItem;

/**
 * Calcula y persiste los totales de una compra desde sus items.
 *
 * Fuente única de verdad para la aritmética fiscal de compras. Antes vivía
 * duplicada en `Purchase::recalculateTotals()` (modelo) y
 * `PurchaseService::recalculateTotals()` (service privado), lo que obligaba
 * a sincronizar manualmente cualquier cambio en las reglas. Ahora cualquier
 * modificación fiscal (tasa, exenciones especiales, agregar taxable/exempt)
 * se hace en un solo lugar.
 *
 * Responsabilidad exclusiva (SRP):
 *   - Dado un Purchase con sus PurchaseItems cargados, calcular por línea
 *     subtotal/isv_amount/total y a nivel compra taxable_total/exempt_total/
 *     subtotal/isv/total, y persistirlos.
 *
 * No se ocupa de:
 *   - Validar estado (eso lo hace PurchaseService)
 *   - Registrar movimientos de inventario (eso lo hace PurchaseService)
 *   - Actualizar costo de productos (eso lo hace PurchaseService)
 *
 * Convención de costos: `unit_cost` llega CON ISV incluido (así lo ingresa
 * el usuario en el form, así lo almacena kardex como snapshot histórico).
 * La base sin ISV se deriva dividiendo por el multiplicador de `config/tax.php`.
 */
class PurchaseTotalsCalculator
{
    /**
     * Recalcular y persistir todos los totales del Purchase.
     *
     * Usa updateQuietly en items y compra para no disparar Activity Log
     * por cada recálculo (la actividad fiscalmente relevante es confirm/cancel).
     */
    public function recalculate(Purchase $purchase): void
    {
        $purchase->loadMissing('items');

        $multiplier   = (float) config('tax.multiplier', 1.15);
        $documentType = $purchase->document_type;

        $subtotal     = 0.0;
        $taxableTotal = 0.0;
        $exemptTotal  = 0.0;
        $isvTotal     = 0.0;

        foreach ($purchase->items as $item) {
            [$lineBase, $lineIsv, $lineTotal] = $this->calculateLine($item, $multiplier, $documentType);

            $item->updateQuietly([
                'subtotal'   => $lineBase,
                'isv_amount' => $lineIsv,
                'total'      => $lineTotal,
            ]);

            $subtotal += $lineBase;
            $isvTotal += $lineIsv;

            // taxable/exempt totals: en RI todo va a exempt aunque el producto
            // sea Gravado15, porque el Libro de Compras no recibe RIs y la
            // separación no aporta valor — además mantener taxable_total > 0
            // en RIs confundiría reportes que filtren por base imponible.
            $countsAsTaxable = $documentType?->separatesIsv()
                && $item->tax_type === TaxType::Gravado15;

            if ($countsAsTaxable) {
                $taxableTotal += $lineBase;
            } else {
                $exemptTotal += $lineBase;
            }
        }

        $purchase->updateQuietly([
            'subtotal'      => round($subtotal, 2),
            'taxable_total' => round($taxableTotal, 2),
            'exempt_total'  => round($exemptTotal, 2),
            'isv'           => round($isvTotal, 2),
            'total'         => round($subtotal + $isvTotal, 2),
        ]);
    }

    /**
     * Calcular base sin ISV, ISV e importe total de una línea.
     *
     * @return array{0: float, 1: float, 2: float} [base, isv, total]
     */
    private function calculateLine(
        PurchaseItem $item,
        float $multiplier,
        ?SupplierDocumentType $documentType,
    ): array {
        return self::calculateLineFigures(
            unitCost: (float) $item->unit_cost,
            quantity: (int) $item->quantity,
            taxType: $item->tax_type,
            multiplier: $multiplier,
            documentType: $documentType,
        );
    }

    /**
     * Fórmula pura expuesta para que otros callers (ej. PurchaseItemObserver,
     * buildSummary del form) puedan calcular las cifras de una línea sin
     * instanciar el servicio.
     *
     * El `$documentType` controla si se separa el ISV o no. Si es null o
     * `separatesIsv() === true` (factura, NC, ND), aplica back-out cuando el
     * tax_type del item es Gravado15. Si `separatesIsv() === false` (Recibo
     * Interno), trata todo como exento aunque el producto sea Gravado15:
     * subtotal = total, ISV = 0. Razón: en RI no hay ISV deducible, el precio
     * pagado es el precio final sin desglose fiscal.
     *
     * Se extrajo desde `calculateLine()` para evitar duplicación — cualquier
     * path que cree PurchaseItems sin pasar por Filament/afterCreate necesita
     * completar subtotal/isv_amount/total para satisfacer las columnas NOT NULL,
     * y la única fuente legítima de esa aritmética es este servicio.
     *
     * @return array{0: float, 1: float, 2: float} [base, isv, total]
     */
    public static function calculateLineFigures(
        float $unitCost,
        int $quantity,
        ?TaxType $taxType,
        ?float $multiplier = null,
        ?SupplierDocumentType $documentType = null,
    ): array {
        $multiplier ??= (float) config('tax.multiplier', 1.15);
        $lineTotal = round($unitCost * $quantity, 2);

        // Separa ISV solo si el documento lo amerita (factura/NC/ND) Y el
        // producto es Gravado15. RI nunca separa, aunque el producto sea
        // gravado en su catálogo (eso aplica para ventas, no para compras
        // informales sin documento SAR).
        $shouldSeparate = ($documentType?->separatesIsv() ?? true)
            && $taxType === TaxType::Gravado15;

        if ($shouldSeparate) {
            $lineBase = round($lineTotal / $multiplier, 2);
            $lineIsv  = round($lineTotal - $lineBase, 2);
        } else {
            $lineBase = $lineTotal;
            $lineIsv  = 0.0;
        }

        return [$lineBase, $lineIsv, $lineTotal];
    }
}

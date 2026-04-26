<?php

use App\Models\Purchase;
use App\Services\Purchases\PurchaseTotalsCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix: recalcula los Recibos Internos existentes para que NO tengan
 * separación de ISV. La regla nueva (ver SupplierDocumentType::separatesIsv)
 * dice que en RI todo se trata como exento — subtotal=total, isv=0.
 *
 * Motivación
 * ──────────
 * Hasta esta migración, PurchaseTotalsCalculator aplicaba back-out de ISV en
 * todos los items con tax_type=Gravado15, sin importar el document_type. Eso
 * generaba un "ISV fantasma" en RIs (compras informales sin documento SAR
 * que NO dan crédito fiscal). Síntomas:
 *   - Compras de L 100 en RI quedaban registradas como L 86.96 base + L 13.04 ISV
 *   - El ISV de RIs no es deducible — el contador no debería verlo en sus reportes
 *   - El costo del inventario quedaba subvaluado (debería ser el precio total
 *     pagado, no la base sin ISV)
 *
 * Qué hace este fix
 * ─────────────────
 * Para cada Purchase con document_type=99 (RI):
 *   1. Recalcula sus totales con el calculator actual (que ya respeta la
 *      nueva regla via separatesIsv()).
 *   2. El calculator persiste subtotal/isv_amount/total por línea y a nivel
 *      compra usando updateQuietly — no contamina activity log.
 *   3. taxable_total queda en 0 y exempt_total absorbe la base completa.
 *
 * Idempotente: si un RI ya tiene isv=0, recalcular no cambia nada.
 *
 * Forward-only: down() no revierte porque el estado anterior era data
 * corruption fiscal (ISV fantasma). Revertir reintroduciría el bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Filtra solo RIs que tienen ISV mal calculado (isv > 0). Si no hay
        // ninguno, sale en 0 y la migración no toca nada — útil cuando se
        // corre en un entorno limpio post-fix.
        $rIsAfectados = DB::table('purchases')
            ->where('document_type', '99')
            ->where('isv', '>', 0)
            ->pluck('id');

        if ($rIsAfectados->isEmpty()) {
            echo "  → No hay Recibos Internos con ISV separado para corregir.\n";
            return;
        }

        $calculator = app(PurchaseTotalsCalculator::class);
        $corregidos = 0;

        foreach ($rIsAfectados as $purchaseId) {
            $purchase = Purchase::with('items')->find($purchaseId);

            if (! $purchase) {
                continue; // soft-deleted u otro race; ignorar
            }

            $calculator->recalculate($purchase);
            $corregidos++;
        }

        echo "  → Recibos Internos recalculados sin separación de ISV: {$corregidos}\n";
    }

    public function down(): void
    {
        // Forward-only — ver docblock de la migración.
        // El estado anterior tenía ISV fantasma en RIs (data corruption fiscal).
        // Revertir ese estado reintroduciría el bug.
    }
};

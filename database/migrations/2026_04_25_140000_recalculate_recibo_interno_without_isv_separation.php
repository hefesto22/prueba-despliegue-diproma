<?php

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
 *   1. Cada línea queda con subtotal = total = unit_cost × quantity, ISV 0.
 *   2. La compra queda con taxable_total 0, isv 0 y exempt_total = subtotal =
 *      total = suma de sus líneas.
 * Escribe con DB::table() — no dispara observers ni activity log.
 *
 * Nota 2026-09: originalmente delegaba en PurchaseTotalsCalculator. Ese
 * servicio se eliminó en la Fase 1 del rediseño Compras + Producto-lote
 * (las compras ya no tienen líneas), así que la aritmética del RI quedó
 * inline aquí — es exactamente la que el calculator aplicaba a un RI. En
 * entornos donde esta migración ya corrió no cambia nada.
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
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($rIsAfectados->isEmpty()) {
            echo "  → No hay Recibos Internos con ISV separado para corregir.\n";

            return;
        }

        foreach ($rIsAfectados as $purchaseId) {
            DB::transaction(function () use ($purchaseId) {
                // En RI no se separa ISV: lo pagado por línea es su base.
                DB::table('purchase_items')
                    ->where('purchase_id', $purchaseId)
                    ->update([
                        'subtotal' => DB::raw('ROUND(unit_cost * quantity, 2)'),
                        'isv_amount' => 0,
                        'total' => DB::raw('ROUND(unit_cost * quantity, 2)'),
                    ]);

                $base = round((float) DB::table('purchase_items')
                    ->where('purchase_id', $purchaseId)
                    ->sum('total'), 2);

                DB::table('purchases')
                    ->where('id', $purchaseId)
                    ->update([
                        'subtotal' => $base,
                        'taxable_total' => 0,
                        'exempt_total' => $base,
                        'isv' => 0,
                        'total' => $base,
                    ]);
            });
        }

        $corregidos = $rIsAfectados->count();

        echo "  → Recibos Internos recalculados sin separación de ISV: {$corregidos}\n";
    }

    public function down(): void
    {
        // Forward-only — ver docblock de la migración.
        // El estado anterior tenía ISV fantasma en RIs (data corruption fiscal).
        // Revertir ese estado reintroduciría el bug.
    }
};

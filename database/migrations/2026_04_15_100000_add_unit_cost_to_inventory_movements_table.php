<?php

use App\Enums\MovementType;
use App\Models\Purchase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agregar snapshot del costo unitario a cada movimiento de inventario.
 *
 * Motivación:
 * - Habilita valorización histórica de inventario a cualquier fecha.
 * - Habilita cálculo exacto de ganancia bruta (resuelve deuda técnica
 *   en DashboardStatsService::grossProfitThisMonth).
 * - Permite auditoría: qué costo tenía cada salida al momento exacto.
 *
 * Nullable porque los datos pre-migración no siempre son reconstruibles
 * con certeza. Solo hacemos backfill donde el dato es exacto (movimientos
 * de compra → purchase_items.unit_cost). Los movimientos de venta/ajuste
 * pre-migración quedan NULL y los reportes los excluyen honestamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)
                ->nullable()
                ->after('quantity')
                ->comment('Costo unitario al momento del movimiento. Null para datos pre-migración de ventas/ajustes.');
        });

        // Backfill solo donde el dato es reconstruible con certeza:
        // movimientos de compra → purchase_items conserva el unit_cost exacto.
        // Los movimientos de venta/ajuste pre-migración quedan NULL a propósito.
        DB::statement("
            UPDATE inventory_movements im
            INNER JOIN purchase_items pi
                ON pi.purchase_id = im.reference_id
               AND pi.product_id = im.product_id
            SET im.unit_cost = pi.unit_cost
            WHERE im.reference_type = ?
              AND im.type IN (?, ?)
              AND im.unit_cost IS NULL
        ", [
            Purchase::class,
            MovementType::EntradaCompra->value,
            MovementType::SalidaAnulacionCompra->value,
        ]);
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};

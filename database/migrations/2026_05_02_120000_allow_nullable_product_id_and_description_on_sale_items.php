<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite que `sale_items` represente líneas SIN producto del catálogo.
 *
 * Razón: el módulo de Reparaciones genera ventas que incluyen conceptos
 * que NO están en el catálogo de productos:
 *   - Honorarios profesionales (servicio, no inventariable).
 *   - Piezas externas (compradas puntualmente para esa reparación, no
 *     se quieren registrar en el catálogo permanente).
 *
 * Antes de este cambio, `sale_items.product_id` era NOT NULL — toda venta
 * tenía que pasar por el catálogo. La entrega de reparaciones no podía
 * funcionar sin reinventar el modelo de SaleItem.
 *
 * Cambios:
 *   1. `product_id` ahora es nullable (con la FK ya existente, que sigue
 *      validando integridad cuando hay valor).
 *   2. Nueva columna `description` (string) — texto libre para SaleItems
 *      sin producto. Cuando hay producto, queda null y la factura usa
 *      `product.name` como antes.
 *
 * Compatibilidad: las ventas existentes y las del POS NO se ven afectadas
 * — siguen llenando `product_id` y dejando `description` null. Solo las
 * SaleItems creadas desde RepairDeliveryService usan el nuevo flujo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            // Drop la FK existente para poder modificar la columna a nullable
            $table->dropForeign(['product_id']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('product_id')
                ->nullable()
                ->change()
                ->constrained()
                ->restrictOnDelete();

            $table->string('description', 300)->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn('description');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('product_id')
                ->nullable(false)
                ->change()
                ->constrained()
                ->restrictOnDelete();
        });
    }
};

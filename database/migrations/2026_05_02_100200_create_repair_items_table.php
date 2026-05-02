<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de cotización de una Reparación.
 *
 * Cada línea tiene un `source` (RepairItemSource) que define su origen:
 *   - HonorariosReparacion / HonorariosMantenimiento → exento, descripción libre.
 *   - PiezaExterna   → comprada para esta reparación. Requiere `condition`
 *                      (Nueva → gravado 15% / Usada → exento).
 *                      Opcional `external_supplier` (a quién se compró).
 *   - PiezaInventario → sale del stock propio. Requiere `product_id`.
 *                      Tax type derivado del Product.
 *
 * Diseño cantidad: `decimal(8,2)` para soportar fracciones (ej: "1.5 horas
 * de mano de obra", "0.25 de un kit"). Las piezas físicas suelen ser enteras
 * pero el dominio lo permite.
 *
 * `unit_price` se guarda CON ISV INCLUIDO cuando es gravado (consistente
 * con SaleItem.unit_price). El cálculo del precio base sin ISV se hace en
 * el modelo vía accessor.
 *
 * `unit_cost` (opcional) registra lo que Diproma pagó por la pieza externa.
 * Sirve para reportes de margen de la reparación. NO se imprime al cliente.
 *
 * Recálculo: cuando se modifica un item, RepairTaxCalculator recalcula los
 * totales del repair padre. NUNCA se suma desde el frontend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_items', function (Blueprint $table) {
            $table->id();

            // Cascade: borrar repair → borrar items (no hay items huérfanos).
            $table->foreignId('repair_id')
                ->constrained()
                ->cascadeOnDelete();

            // Origen de la línea (RepairItemSource enum).
            $table->string('source', 40);

            // Solo cuando source = PiezaInventario (FK al catálogo).
            // restrictOnDelete: no se puede borrar un Product referenciado
            // por una reparación entregada (auditoría SAR).
            $table->foreignId('product_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            // Solo cuando source = PiezaExterna (RepairItemCondition).
            $table->string('condition', 10)->nullable();

            // Texto libre. Obligatorio cuando NO hay product_id.
            $table->string('description', 300);

            // Quién vendió la pieza externa (opcional, para reportes).
            $table->string('external_supplier', 200)->nullable();

            // ─── Cantidades y precios ────────────────────────────────────
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_cost', 12, 2)->nullable();    // costo (interno)
            $table->decimal('unit_price', 12, 2);               // precio (con ISV si gravado)

            // Tipo fiscal derivado (gravado_15 / exento). Se guarda explícito
            // en la fila para auditar incluso si después cambia la lógica
            // de derivación (ej: el tax_type del Product cambia tras una
            // reparación entregada — el item original debe mantener su tipo).
            $table->string('tax_type', 20);

            // Totales por línea (calculados por RepairTaxCalculator).
            $table->decimal('subtotal', 12, 2)->default(0);     // base sin ISV
            $table->decimal('isv_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);        // con ISV

            $table->text('notes')->nullable();

            $table->timestamps();

            // ─── Índices ─────────────────────────────────────────────────
            // Reportes: ítems por tipo fiscal en un periodo (libro de ventas).
            $table->index(['repair_id', 'source'], 'repair_items_repair_source_idx');
            // Reportes de uso de inventario en reparaciones.
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_items');
    }
};

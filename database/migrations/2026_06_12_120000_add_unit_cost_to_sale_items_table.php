<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot del costo unitario en la línea de venta.
 *
 * Motivación: las líneas SIN producto del catálogo (honorarios y piezas
 * externas de reparaciones) no tienen movimiento de kardex, así que el
 * cálculo de ganancia bruta del dashboard no podía conocer su costo y las
 * excluía por completo (ni ingreso ni costo). Con esta columna:
 *
 *   - Piezas de inventario  → el costo sigue saliendo del kardex (CPP
 *     congelado al momento de la venta — fuente más exacta).
 *   - Piezas externas       → costo = lo registrado en la cotización.
 *   - Honorarios            → sin costo (NULL) = ganancia pura.
 *
 * Nullable: las ventas POS existentes no la llenan (su costo vive en el
 * kardex) y las líneas históricas quedan NULL sin romper nada.
 * Sin índice: nunca se filtra por costo — solo se agrega en SUM().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};

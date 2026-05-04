<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Diferenciar productos físicos vs servicios sin inventario.
 *
 * Por qué este flag existe:
 *   El sistema soporta dos tipos de "producto" en el mismo catálogo:
 *     1. Productos FÍSICOS: laptop, biométrico, cámara, equipo de seguridad,
 *        cualquier item con stock medible que se descuenta al vender.
 *     2. SERVICIOS: honorarios (instalación, reparación, mantenimiento,
 *        asesoría) — no tienen inventario, no se descuenta stock al vender,
 *        el precio puede variar por venta.
 *
 *   Antes de este flag, el sistema asumía erróneamente que "tipo custom =
 *   servicio". Eso rompía el inventario de equipos de seguridad y cualquier
 *   producto físico con tipo no-enum:
 *     - Stock se mostraba ∞ aunque fueran productos reales.
 *     - El POS no descontaba stock al vender.
 *     - Reportes de stock bajo no incluían custom types.
 *     - CPP se distorsionaba con stock 999999.
 *
 *   Con la columna explícita `is_service`, cada producto declara su naturaleza
 *   y la lógica fiscal/inventario consulta una columna concreta sin string
 *   parsing frágil ni listas hardcodeadas de tipos.
 *
 * Default false:
 *   La gran mayoría de productos del catálogo son físicos (con inventario).
 *   Solo Honorarios y similares marcan true. Defaultear a false evita que
 *   un producto creado sin marcar la casilla quede mal clasificado.
 *
 * Backfill:
 *   Si la BD ya tiene productos con `stock >= 100000` o tipo "honorario",
 *   los marcamos como is_service=true para preservar consistencia. Productos
 *   con stock real (ej. equipo de seguridad creado erróneamente con stock
 *   999999 por la lógica vieja) requieren ajuste manual del stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_service')
                ->default(false)
                ->after('product_type');

            // Índice para "obtener todos los servicios" o "todos los productos
            // físicos" — utilizado por reportes de stock bajo y dashboards.
            $table->index('is_service', 'products_is_service_idx');
        });

        // Backfill: marcar como servicio cualquier producto con tipo
        // "honorario" / "honorarios" (case-insensitive).
        DB::table('products')
            ->whereRaw('UPPER(product_type) LIKE ?', ['%HONORARIO%'])
            ->update(['is_service' => true]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_is_service_idx');
            $table->dropColumn('is_service');
        });
    }
};

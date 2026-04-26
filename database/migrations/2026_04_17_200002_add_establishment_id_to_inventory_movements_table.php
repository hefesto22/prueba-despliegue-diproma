<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F6a — Agrega establishment_id a inventory_movements (kardex por sucursal).
 *
 * Índice crítico: [establishment_id, product_id, created_at] — es LA query
 * que domina el dashboard de stock por sucursal y el reporte de kardex
 * segregado. Supera al índice previo [product_id, created_at] cuando el
 * filtro de sucursal está presente.
 *
 * Se conserva [product_id, created_at] para el kardex consolidado
 * (todas las sucursales de un mismo producto) que sigue siendo útil
 * en auditorías y reportes globales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('establishment_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
        });

        $matrizId = DB::table('establishments')
            ->where('is_main', true)
            ->value('id');

        if ($matrizId !== null) {
            DB::table('inventory_movements')
                ->whereNull('establishment_id')
                ->update(['establishment_id' => $matrizId]);
        }

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->index(
                ['establishment_id', 'product_id', 'created_at'],
                'inv_mov_estab_product_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('inv_mov_estab_product_created_idx');
            $table->dropForeign(['establishment_id']);
            $table->dropColumn('establishment_id');
        });
    }
};

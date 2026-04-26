<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F6a — Agrega establishment_id a purchases para segregación por sucursal.
 *
 * Mismo patrón que sales: nullable + nullOnDelete (consistencia con invoices),
 * backfill inline a matriz, índices compuestos para Libro de Compras filtrado
 * y listados Filament por sucursal.
 *
 * Desbloquea el filtro por sucursal en PurchaseBookService::build() (deuda
 * documentada al cerrar F2 — Libro de Compras SAR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
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
            DB::table('purchases')
                ->whereNull('establishment_id')
                ->update(['establishment_id' => $matrizId]);
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->index(['establishment_id', 'date'], 'purchases_estab_date_idx');
            $table->index(['establishment_id', 'status', 'date'], 'purchases_estab_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('purchases_estab_status_date_idx');
            $table->dropIndex('purchases_estab_date_idx');
            $table->dropForeign(['establishment_id']);
            $table->dropColumn('establishment_id');
        });
    }
};

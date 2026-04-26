<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F6a — Agrega establishment_id a sales para segregación de operaciones por sucursal.
 *
 * Decisiones de diseño:
 *  - nullable() + nullOnDelete(): consistente con invoices.establishment_id.
 *    La integridad de que TODA venta nueva tenga sucursal se valida a nivel de
 *    FormRequest / SaleService (invariante de dominio), no via NOT NULL en DB
 *    — permite que el backfill inicial sea seguro aun si falta data en algún
 *    edge case histórico.
 *  - Backfill inline a Establishment::main() dentro del up() — si no hay
 *    matriz aún (fresh install sin seeder), las filas quedan null y el seeder
 *    posterior las completa al crear la matriz.
 *  - Índices compuestos orientados a las queries reales:
 *       [establishment_id, date]          → Libro de Ventas por sucursal + reportes
 *       [establishment_id, status, date]  → listados Filament filtrados por estado
 *    Se conservan los índices previos (['status','date'], ['customer_id','date'])
 *    porque siguen siendo útiles para queries consolidadas cross-sucursal.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Paso 1: agregar columna nullable con FK
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('establishment_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
        });

        // Paso 2: backfill a la matriz (si existe)
        $matrizId = DB::table('establishments')
            ->where('is_main', true)
            ->value('id');

        if ($matrizId !== null) {
            DB::table('sales')
                ->whereNull('establishment_id')
                ->update(['establishment_id' => $matrizId]);
        }

        // Paso 3: índices compuestos para queries por sucursal
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['establishment_id', 'date'], 'sales_estab_date_idx');
            $table->index(['establishment_id', 'status', 'date'], 'sales_estab_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_estab_status_date_idx');
            $table->dropIndex('sales_estab_date_idx');
            $table->dropForeign(['establishment_id']);
            $table->dropColumn('establishment_id');
        });
    }
};

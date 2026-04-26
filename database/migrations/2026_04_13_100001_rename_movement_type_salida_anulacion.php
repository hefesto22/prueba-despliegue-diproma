<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renombrar el valor del enum MovementType:
 * 'salida_anulacion' → 'salida_anulacion_compra'
 *
 * Necesario porque se agregaron tipos de venta y el nombre
 * original era ambiguo (¿anulación de compra o de venta?).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventory_movements')
            ->where('type', 'salida_anulacion')
            ->update(['type' => 'salida_anulacion_compra']);
    }

    public function down(): void
    {
        DB::table('inventory_movements')
            ->where('type', 'salida_anulacion_compra')
            ->update(['type' => 'salida_anulacion']);
    }
};

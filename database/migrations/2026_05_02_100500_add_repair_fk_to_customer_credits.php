<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierra la dependencia circular entre `customer_credits` y `repairs`:
 *
 * `customer_credits.source_repair_id` se creó como `unsignedBigInteger`
 * sin FK porque la tabla `repairs` aún no existía. Ahora que ambas
 * tablas existen, agregamos la FK formal con `nullOnDelete()`.
 *
 * Razón de `nullOnDelete()` (no cascade): si una reparación se borra (soft
 * o hard), el crédito a favor del cliente DEBE persistir — el cliente
 * tiene derecho al saldo independientemente del destino del repair origen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_credits', function (Blueprint $table) {
            $table->foreign('source_repair_id')
                ->references('id')
                ->on('repairs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_credits', function (Blueprint $table) {
            $table->dropForeign(['source_repair_id']);
        });
    }
};

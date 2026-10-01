<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diezmo mensual (2026-09-30).
 *
 * `tithes`: un registro por mes (único por year + month). Guarda la FOTO de
 * las cifras con que se calculó — utilidad del sistema, pagos agregados,
 * base y diezmo — porque las ventas y gastos del mes pueden cambiar después.
 * Recalcular el mismo mes actualiza este registro, no crea otro.
 *
 * `tithe_deductions`: los pagos que el sistema no conoce y se restaron para
 * el diezmo (pago de empleados, etc.). Tabla propia y no JSON: son filas con
 * concepto y monto tipados.
 *
 * Volumen: 12 registros al año. Sin índices extra más allá del único y la FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tithes', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('revenue', 12, 2);
            $table->decimal('cost', 12, 2);
            $table->decimal('expense_purchases', 12, 2);
            $table->decimal('card_fees', 12, 2);
            $table->decimal('net_profit', 12, 2);
            $table->decimal('extra_deductions', 12, 2)->default(0);
            $table->decimal('base_amount', 12, 2);
            $table->decimal('rate', 5, 4);
            $table->decimal('amount', 12, 2);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['year', 'month']);
        });

        Schema::create('tithe_deductions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tithe_id');
            $table->string('concept', 150);
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            // Índice declarado ANTES de la FK para que MySQL lo use para la
            // constraint en vez de crear uno automático duplicado.
            $table->index('tithe_id', 'tithe_deductions_tithe_id_idx');
            $table->foreign('tithe_id')->references('id')->on('tithes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tithe_deductions');
        Schema::dropIfExists('tithes');
    }
};

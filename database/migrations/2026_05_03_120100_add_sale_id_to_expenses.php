<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de gastos automáticos generados por una venta.
 *
 * Por qué se agrega `sale_id` nullable:
 *   Las comisiones bancarias por pagos con tarjeta se crean automáticamente
 *   como Expense al procesar la venta. Sin este FK el gasto solo se
 *   identificaba por texto en `description` ("Comisión POS — VTA-2026-XXX"),
 *   lo que rompía la trazabilidad para:
 *     - Reportes de "comisiones por venta" (requerirían string parsing).
 *     - Auditoría: dado un Expense, saber exactamente qué venta lo originó.
 *     - Reversa selectiva ante anulaciones (futuro — por ahora la comisión
 *       queda intacta porque el banco ya cobró el costo real).
 *
 * Por qué nullable:
 *   La gran mayoría de gastos NO viene de una venta (combustible, papelería,
 *   servicios, etc.). Solo las comisiones bancarias automáticas llevan
 *   sale_id. Hacerlo NOT NULL rompería el modelo existente.
 *
 * Por qué nullOnDelete:
 *   Si una venta se elimina físicamente (no debería pasar — usamos anulación
 *   lógica), no queremos perder el gasto histórico. Mantener el Expense con
 *   sale_id=null preserva la trazabilidad contable.
 *
 * Índice:
 *   Permite query `WHERE sale_id = X` para reportes "comisiones de esta venta"
 *   y `JOIN sales ON expenses.sale_id = sales.id` sin full scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('sale_id')
                ->nullable()
                ->after('user_id')
                ->constrained('sales')
                ->nullOnDelete();

            $table->index('sale_id', 'expenses_sale_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_sale_id_idx');
            $table->dropForeign(['sale_id']);
            $table->dropColumn('sale_id');
        });
    }
};

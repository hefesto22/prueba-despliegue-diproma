<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo compra ← gasto (Fase 1b).
 *
 * Un gasto con factura con CAI (papelería, internet, energía…) genera
 * automáticamente su documento en Compras para que el ISV llegue al Libro de
 * Compras y al crédito fiscal. El gasto es la fuente de verdad; la compra es
 * una copia que ExpenseFiscalDocumentSync mantiene al día.
 *
 * Por qué NO es único: si el gasto se desmarca como deducible, su compra se
 * ANULA (no se borra — rastro fiscal) y si luego se vuelve a marcar se crea
 * otra. Un gasto puede tener varias compras anuladas y como máximo UNA
 * vigente; esa regla la garantiza el servicio con lock sobre el gasto.
 *
 * restrictOnDelete: los gastos no se eliminan (regla del dominio fiscal).
 *
 * El índice se declara ANTES de la FK para que MySQL lo use para la
 * constraint en vez de crear uno automático duplicado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('expense_id')->nullable()->after('establishment_id');

            $table->index('expense_id', 'purchases_expense_id_idx');

            $table->foreign('expense_id')
                ->references('id')
                ->on('expenses')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['expense_id']);
            $table->dropIndex('purchases_expense_id_idx');
            $table->dropColumn('expense_id');
        });
    }
};

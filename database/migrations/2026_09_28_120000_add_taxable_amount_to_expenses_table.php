<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importe gravado 15% de la factura de un gasto (Fase 1b).
 *
 * Un gasto con factura con CAI ahora genera su documento en el Libro de
 * Compras (ver ExpenseFiscalDocumentSync), y el libro necesita separar la
 * base gravada de la exenta. Con solo `amount_total` + `isv_amount` habría
 * que deducir el gravado como ISV / 0.15, que difiere en centavos de lo que
 * imprime la factura. Se guarda tal como viene en el documento; el exento se
 * deriva (total − gravado − ISV).
 *
 * Nullable: los gastos sin factura no lo usan, y los gastos deducibles
 * registrados antes de esta fase quedan en NULL — así se distinguen y NO se
 * copian al Libro de Compras (decisión 2026-09-28: solo de aquí en adelante).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('taxable_amount', 12, 2)->nullable()->after('amount_total');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('taxable_amount');
        });
    }
};

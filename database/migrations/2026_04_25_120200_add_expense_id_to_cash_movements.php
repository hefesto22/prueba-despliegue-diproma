<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo bidireccional Expense ↔ CashMovement.
 *
 * Cuando un Expense se paga con efectivo de caja chica, ExpenseService crea
 * además un CashMovement (type=expense, payment_method=efectivo) que afecta
 * el saldo físico del cajón. La FK expense_id permite navegar desde el
 * movimiento al gasto contable y viceversa (Expense.cashMovement vía
 * cash_movement_id en expenses).
 *
 * Por qué nullable:
 *   - No todo CashMovement nace de un Expense (sale_income, opening_balance,
 *     supplier_payment, deposit, adjustment, closing_balance no tienen Expense
 *     asociado). expense_id IS NOT NULL es exclusivo del flow "gasto en
 *     efectivo".
 *
 * Por qué nullOnDelete y no cascade:
 *   - Los Expense no se eliminan (regla de la migración expenses). Si en el
 *     futuro se permite eliminar un Expense, el CashMovement debe sobrevivir
 *     porque ya forma parte del kardex histórico cerrado de su sesión.
 *     nullOnDelete preserva el movimiento sin la referencia al gasto.
 *
 * Sin índice dedicado:
 *   - La FK ya genera índice (Laravel/PostgreSQL/MySQL). La query "obtener
 *     movement por expense_id" usa ese índice y no hay otra query frecuente
 *     que justifique uno adicional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('expense_id')
                ->nullable()
                ->after('reference_id')
                ->constrained('expenses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropForeign(['expense_id']);
            $table->dropColumn('expense_id');
        });
    }
};

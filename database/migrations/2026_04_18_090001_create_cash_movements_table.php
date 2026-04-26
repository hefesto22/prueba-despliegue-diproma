<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos de caja — líneas individuales que afectan el saldo de una sesión.
 *
 * Cada movimiento se asocia a una `cash_sessions` abierta. Solo movimientos
 * con `payment_method = efectivo` afectan el saldo físico de caja; los demás
 * métodos (tarjeta, transferencia, cheque) son informativos para reportes.
 *
 * Tipos de movimiento (CashMovementType):
 *   - opening_balance: asentamiento inicial al abrir caja.
 *   - sale_income:     ingreso por venta (asociado a una Sale vía reference).
 *   - expense:         gasto menor (combustible, papelería, etc).
 *   - supplier_payment: pago a proveedor con efectivo de caja.
 *   - deposit:         depósito bancario (saca efectivo de caja).
 *   - adjustment:      ajuste manual con justificación.
 *   - closing_balance: asentamiento final al cerrar caja.
 *
 * Por qué `amount` siempre positivo + `type` determina signo:
 *   - Evita bugs de signo en reportes. Un amount=100 con type=expense es
 *     inequívoco; con signo el mismo dato podría representar algo distinto
 *     según el contexto.
 *   - La función `signedAmountForCash()` en CashMovementType centraliza la
 *     conversión a signo contable.
 *
 * Polimorfismo ligero vía reference_type/reference_id:
 *   - Permite asociar un movimiento a una Sale, Purchase, u otro modelo sin
 *     agregar una FK por cada tipo (YAGNI respetado). Con índice compuesto
 *     para búsqueda por referencia.
 *
 * Índices:
 *   - (cash_session_id, occurred_at): reporte cronológico por sesión.
 *   - (reference_type, reference_id): "¿qué movimiento generó esta venta?".
 *   - (cash_session_id, payment_method): totales por método al cerrar caja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();

            // Sesión que contiene este movimiento. cascadeOnDelete porque un
            // movimiento sin sesión no tiene sentido — pero operativamente
            // las sesiones no se eliminan (son históricas).
            $table->foreignId('cash_session_id')
                ->constrained()
                ->cascadeOnDelete();

            // Usuario que registró el movimiento (puede ser distinto al que
            // abrió la caja si se relevan en turno).
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Tipo de movimiento — enum de dominio (CashMovementType).
            $table->string('type', 32);

            // Método de pago — solo 'efectivo' afecta el saldo físico.
            // String y no enum db porque Filament maneja mejor el filtrado
            // y mantiene consistencia con sales/purchases.
            $table->string('payment_method', 32);

            // Siempre positivo. El signo contable lo determina el enum `type`.
            $table->decimal('amount', 12, 2);

            // Categoría para egresos (combustible, papelería, mantenimiento...).
            // Nullable porque solo aplica a `type = expense`.
            $table->string('category', 64)->nullable();

            $table->text('description')->nullable();

            // Asociación polimórfica opcional: una sale_income apunta a
            // reference_type='App\Models\Sale' + reference_id=<sale_id>.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // Momento real del movimiento (puede diferir de created_at si se
            // registra un gasto ocurrido antes durante el turno).
            $table->timestamp('occurred_at');

            $table->timestamps();

            // Índices diseñados para las queries reales del módulo.
            $table->index(['cash_session_id', 'occurred_at'], 'cash_mov_session_occurred_idx');
            $table->index(['cash_session_id', 'payment_method'], 'cash_mov_session_pm_idx');
            $table->index(['reference_type', 'reference_id'], 'cash_mov_reference_idx');
            $table->index(['cash_session_id', 'type'], 'cash_mov_session_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agregar `payment_method` a `sales`.
 *
 * Contexto C2 (integración Sales ↔ Caja):
 *   - Toda venta nueva debe declarar el método de pago explícitamente
 *     (SaleService::processSale lo exige).
 *   - `payment_method = efectivo` es el único que afecta el saldo físico
 *     de la caja (CashBalanceCalculator se apoya en PaymentMethod::affectsCashBalance).
 *
 * Backfill:
 *   - Ventas previas a C2 se asumen en efectivo (único método histórico del
 *     POS hasta ahora). Default de columna = 'efectivo' + NOT NULL cubre
 *     tanto la migración actual como cualquier inserción que olvide el campo.
 *   - A nivel aplicación, SaleService NO se apoya en el default: lo exige
 *     explícito para que el caller decida de forma consciente.
 *
 * Sin índice por ahora:
 *   - payment_method no es columna de filtro primario. Los reportes de cuadre
 *     por método se hacen dentro de la sesión (cash_movements ya tiene índice
 *     compuesto con payment_method). Si reportes futuros lo requieren, se
 *     agrega un índice compuesto (establishment_id, payment_method, date).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('payment_method', 32)
                ->default('efectivo')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};

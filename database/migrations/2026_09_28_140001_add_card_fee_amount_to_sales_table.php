<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comisión del procesador de tarjeta, guardada en la venta que la origina.
 *
 * Antes CardFeeRecorder creaba un Gasto por cada venta con tarjeta. Al
 * desaparecer el módulo de Gastos (todo en Compras, 2026-09-28) la comisión
 * pasa a ser un dato de la venta: no es una compra a un proveedor, la retiene
 * el banco del depósito. La Utilidad Neta la resta del mes de la venta.
 *
 * Default 0: ventas en efectivo/transferencia no tienen comisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('card_fee_amount', 12, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('card_fee_amount');
        });
    }
};

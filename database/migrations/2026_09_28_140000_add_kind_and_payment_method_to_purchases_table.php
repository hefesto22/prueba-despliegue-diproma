<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Todo en Compras" (2026-09-28): Compras pasa a ser el único registro de
 * salidas de dinero a terceros — mercadería Y gastos operativos. El módulo
 * de Gastos desaparece.
 *
 * Columnas nuevas:
 *   - kind: PurchaseKind (mercaderia | gasto). Default 'mercaderia' porque
 *     todas las compras existentes lo son. La Utilidad Neta resta solo las
 *     de tipo gasto; la mercadería entra por el costo de lo vendido.
 *   - expense_category: ExpenseCategory, solo para gastos (Reporte Mensual).
 *   - description: concepto del gasto ("Gasolina moto mensajero"). En
 *     mercadería el detalle ya está en el documento del proveedor.
 *   - payment_method: PaymentMethod. Si es efectivo, confirmar la compra
 *     saca el dinero de la caja abierta. Nullable: las compras anteriores
 *     no lo registraban y no se inventa.
 *   - legacy_expense_id: id del gasto del que se migró la compra (ver
 *     2026_09_28_140002). Sin FK a propósito: la tabla expenses se elimina
 *     en un deploy posterior y este dato queda como rastro de auditoría.
 *
 * Índice (kind, status, date): la Utilidad Neta y el Reporte Mensual de
 * Gastos filtran "gastos confirmados del mes" en cada carga del dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('kind', 20)->default('mercaderia')->after('document_type');
            $table->string('expense_category', 64)->nullable()->after('kind');
            $table->string('description', 500)->nullable()->after('expense_category');
            $table->string('payment_method', 32)->nullable()->after('payment_status');
            $table->unsignedBigInteger('legacy_expense_id')->nullable()->after('notes');

            $table->index(['kind', 'status', 'date'], 'purchases_kind_status_date_index');
            $table->index('legacy_expense_id', 'purchases_legacy_expense_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('purchases_kind_status_date_index');
            $table->dropIndex('purchases_legacy_expense_id_index');
            $table->dropColumn(['kind', 'expense_category', 'description', 'payment_method', 'legacy_expense_id']);
        });
    }
};

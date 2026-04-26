<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos contables — entidad agregado primario para egresos del negocio.
 *
 * Concepto del dominio:
 *   - Un Expense representa el egreso fiscal/contable: lo que el contador
 *     necesita declarar en ISR/ISV. Lleva datos del proveedor, factura,
 *     ISV pagado, y deducibilidad fiscal.
 *   - Un CashMovement representa el efecto físico en el cajón. Solo aplica
 *     cuando el gasto se paga con efectivo de caja chica.
 *
 * Por qué tabla separada de cash_movements:
 *   - SRP: cash_movements es el kardex de caja (saldo físico). Cargarle
 *     responsabilidades fiscales (RTN proveedor, ISV deducible) lo convierte
 *     en una bolsa de propósito general.
 *   - Gastos pagados con tarjeta/transferencia/cheque NO deben aparecer en
 *     el kardex de efectivo, pero SÍ deben quedar registrados para el
 *     contador. Una sola entidad "gasto" lo resuelve sin duplicación.
 *
 * Vínculo con CashMovement:
 *   - Cuando payment_method = efectivo, ExpenseService crea un CashMovement
 *     vinculado vía expense_id (FK en cash_movements, ver migración
 *     2026_04_25_120200_add_expense_id_to_cash_movements).
 *   - Cuando payment_method ≠ efectivo, no se crea CashMovement: el gasto
 *     no afecta el saldo de caja física.
 *
 * Campos fiscales (todos nullable porque no todo gasto trae factura):
 *   - provider_name / provider_rtn: del proveedor que emitió la factura.
 *     Sin proveedor identificado (ej. taxi sin recibo) quedan null.
 *   - provider_invoice_number / provider_invoice_cai: número y CAI del
 *     comprobante del proveedor. CAI opcional aún teniéndola (no toda
 *     factura emitida en HN incluye CAI visible al cliente).
 *   - isv_amount: monto de ISV pagado al proveedor. Nullable porque no
 *     todo gasto está gravado (servicios exentos, productos exentos).
 *   - is_isv_deductible: el contador marca si genera crédito fiscal.
 *     Default false — preferir no asumir deducibilidad sin revisión.
 *
 * Índices diseñados para queries reales:
 *   - (establishment_id, expense_date): listado por sucursal y mes.
 *   - (category, expense_date): "todos los gastos de combustible este año".
 *   - (payment_method, expense_date): "gastos por método de pago en X mes".
 *   - (provider_rtn): búsqueda de proveedor para auditoría/agrupación.
 *   - (is_isv_deductible, expense_date): contador filtra deducibles del mes.
 *
 * No se usa SoftDeletes:
 *   - Los gastos son registros fiscales-contables; eliminarlos compromete
 *     la trazabilidad ante auditoría SAR. Si un gasto fue cargado por
 *     error, la corrección correcta es marcarlo como anulado o crear un
 *     ajuste — no borrarlo. (Por ahora simplemente no se permite eliminar;
 *     si el negocio lo requiere, se agrega SoftDeletes con migración.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            // Sucursal donde se origina el gasto. restrictOnDelete: borrar
            // sucursal con gastos asociados rompe trazabilidad histórica.
            $table->foreignId('establishment_id')
                ->constrained()
                ->restrictOnDelete();

            // Usuario que registró el gasto (cajero o contador).
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Fecha del gasto (no necesariamente igual a created_at — un
            // gasto del 28/abril puede registrarse el 30/abril).
            $table->date('expense_date');

            // Tipo de gasto (ExpenseCategory enum).
            $table->string('category', 64);

            // Método de pago (PaymentMethod enum).
            $table->string('payment_method', 32);

            // Monto total pagado (incluye ISV si aplica).
            $table->decimal('amount_total', 12, 2);

            // ISV pagado al proveedor — nullable porque no todo gasto está
            // gravado o tiene factura que lo desglose.
            $table->decimal('isv_amount', 12, 2)->nullable();

            // ¿Genera crédito fiscal de ISV en la declaración mensual?
            // Default false: preferir conservador. Contador lo confirma.
            $table->boolean('is_isv_deductible')->default(false);

            $table->text('description');

            // ─── Datos del proveedor (todos opcionales) ─────────
            $table->string('provider_name', 255)->nullable();
            $table->string('provider_rtn', 14)->nullable();
            $table->string('provider_invoice_number', 50)->nullable();
            $table->string('provider_invoice_cai', 50)->nullable();
            $table->date('provider_invoice_date')->nullable();

            // Adjunto futuro (factura escaneada). Out of scope hoy pero la
            // columna existe para cuando se active el feature.
            $table->string('attachment_path', 500)->nullable();

            // ─── Auditoría ──────────────────────────────────────
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // ─── Índices ────────────────────────────────────────
            // Listado mensual por sucursal — query base del reporte.
            $table->index(['establishment_id', 'expense_date'], 'expenses_estab_date_idx');
            // "Todos los gastos de categoría X en período Y".
            $table->index(['category', 'expense_date'], 'expenses_category_date_idx');
            // "Gastos por método de pago" para reporte segregado.
            $table->index(['payment_method', 'expense_date'], 'expenses_paymethod_date_idx');
            // Búsqueda por proveedor (auditoría, agrupación contable).
            $table->index('provider_rtn', 'expenses_provider_rtn_idx');
            // Deducibles del mes — query directa para crédito fiscal ISV.
            $table->index(['is_isv_deductible', 'expense_date'], 'expenses_deductible_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};

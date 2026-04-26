<?php

declare(strict_types=1);

namespace App\Services\Expenses;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use Carbon\CarbonImmutable;

/**
 * Línea única del Reporte Mensual de Gastos.
 *
 * Value object inmutable que normaliza un Expense a la forma que tanto la hoja
 * de detalle del Excel como la tabla de la Page consumen. Una vez construido
 * no muta — previene bugs por mutación accidental al mapear a celdas/filas.
 *
 * Diferencia con `PurchaseBookEntry`:
 *   - PurchaseBook es libro fiscal SAR (formato regulado): documento del
 *     proveedor con tipo 01/03/04, RTN obligatorio, anulación afecta totales.
 *   - ExpensesReport es reporte de gestión interno (no es libro SAR): los
 *     datos fiscales son OPCIONALES (gastos sin factura: taxi, propinas) y
 *     solo importan cuando is_isv_deductible = true.
 *
 * El concepto crítico para el contador: si un gasto está marcado deducible
 * pero le faltan datos fiscales (RTN, # factura o CAI del proveedor), SAR
 * rechaza el crédito fiscal en una eventual auditoría. Por eso exponemos
 * `deducibleIncompleto` como bandera explícita — la Page la usa para
 * resaltar la fila y el Resumen la cuenta como alerta.
 */
final class ExpensesMonthlyReportEntry
{
    public function __construct(
        public readonly int $expenseId,
        public readonly CarbonImmutable $expenseDate,
        public readonly string $categoryLabel,
        public readonly string $categoryValue,
        public readonly string $description,
        public readonly ?string $providerName,
        public readonly ?string $providerRtn,
        public readonly ?string $providerInvoiceNumber,
        public readonly ?string $providerInvoiceCai,
        public readonly ?CarbonImmutable $providerInvoiceDate,
        public readonly float $amountBase,        // amount_total − isv_amount (subtotal)
        public readonly float $isvAmount,
        public readonly float $amountTotal,
        public readonly bool $isIsvDeductible,
        public readonly bool $deducibleIncompleto, // deducible sin RTN/factura/CAI
        public readonly string $paymentMethodLabel,
        public readonly string $paymentMethodValue,
        public readonly bool $affectsCash,
        public readonly string $establishmentName,
        public readonly string $userName,
    ) {}

    /**
     * Construye una entrada a partir de un Expense.
     *
     * El Expense debe venir con relaciones `establishment:id,name` y
     * `user:id,name` cargadas — el caller (Service) se encarga del eager load
     * para evitar N+1.
     */
    public static function fromExpense(Expense $expense): self
    {
        $isv          = (float) ($expense->isv_amount ?? 0);
        $total        = (float) $expense->amount_total;
        $isDeductible = (bool) $expense->is_isv_deductible;

        // Bandera de alerta: deducible sin alguno de los 3 datos que SAR exige
        // como soporte del crédito fiscal. La validación del form ya lo
        // bloquea para gastos NUEVOS, pero un gasto editado o creado antes de
        // la validación puede aparecer así — el reporte tiene que detectarlo.
        $incompleto = $isDeductible && (
            blank($expense->provider_rtn)
            || blank($expense->provider_invoice_number)
            || blank($expense->provider_invoice_cai)
        );

        $category = $expense->category instanceof ExpenseCategory
            ? $expense->category
            : ExpenseCategory::from((string) $expense->category);

        $payment = $expense->payment_method instanceof PaymentMethod
            ? $expense->payment_method
            : PaymentMethod::from((string) $expense->payment_method);

        return new self(
            expenseId: $expense->id,
            expenseDate: CarbonImmutable::instance($expense->expense_date),
            categoryLabel: $category->getLabel(),
            categoryValue: $category->value,
            description: $expense->description,
            providerName: $expense->provider_name,
            providerRtn: $expense->provider_rtn,
            providerInvoiceNumber: $expense->provider_invoice_number,
            providerInvoiceCai: $expense->provider_invoice_cai,
            providerInvoiceDate: $expense->provider_invoice_date !== null
                ? CarbonImmutable::instance($expense->provider_invoice_date)
                : null,
            amountBase: round($total - $isv, 2),
            isvAmount: $isv,
            amountTotal: $total,
            isIsvDeductible: $isDeductible,
            deducibleIncompleto: $incompleto,
            paymentMethodLabel: $payment->getLabel(),
            paymentMethodValue: $payment->value,
            affectsCash: $payment->affectsCashBalance(),
            establishmentName: $expense->establishment?->name ?? '—',
            userName: $expense->user?->name ?? '—',
        );
    }

    /** Etiqueta para columna "Deducible" del Excel y la tabla. */
    public function deducibleLabel(): string
    {
        return $this->isIsvDeductible ? 'Sí' : 'No';
    }

    /**
     * Etiqueta humana del estado fiscal — tres valores posibles:
     *   - "No deducible"            → no genera crédito fiscal
     *   - "Completo"                → deducible y datos en orden
     *   - "Deducible incompleto"    → deducible pero le faltan RTN/factura/CAI
     */
    public function fiscalStatusLabel(): string
    {
        if (! $this->isIsvDeductible) {
            return 'No deducible';
        }

        return $this->deducibleIncompleto ? 'Deducible incompleto' : 'Completo';
    }
}

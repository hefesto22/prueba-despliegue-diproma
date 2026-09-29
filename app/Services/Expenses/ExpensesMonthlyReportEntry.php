<?php

declare(strict_types=1);

namespace App\Services\Expenses;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\Purchase;
use App\Models\Sale;
use Carbon\CarbonImmutable;

/**
 * Línea única del Reporte Mensual de Gastos.
 *
 * Value object inmutable que normaliza cada gasto a la forma que consumen la
 * hoja de detalle del Excel y la tabla de la Page.
 *
 * Desde "todo en Compras" (2026-09-28) los gastos vienen de dos fuentes:
 *   - Compras tipo Gasto confirmadas (fromPurchase): con Factura (ISV como
 *     crédito fiscal) o con Recibo Interno (sin CAI, sin ISV).
 *   - Comisiones de tarjeta guardadas en las ventas (fromCardFee).
 *
 * Diferencia con `PurchaseBookEntry`: el Libro de Compras es el libro fiscal
 * SAR (solo facturas); este es un reporte de gestión de todos los gastos.
 *
 * `deducibleIncompleto` marca facturas a las que les falta RTN del
 * proveedor, número o CAI: SAR rechazaría ese crédito fiscal en una
 * auditoría. La Page resalta la fila y el Resumen la cuenta como alerta.
 */
final class ExpensesMonthlyReportEntry
{
    public function __construct(
        public readonly string $reference,       // # de compra o de venta (comisión)
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
     * Entrada a partir de una compra tipo Gasto.
     *
     * La compra debe venir con `establishment`, `supplier` y `createdBy`
     * cargados — el Service hace el eager load para evitar N+1.
     */
    public static function fromPurchase(Purchase $purchase): self
    {
        $isInvoice = $purchase->document_type?->separatesIsv() ?? false;

        $incompleto = $isInvoice && (
            blank($purchase->supplier?->rtn)
            || blank($purchase->supplier_invoice_number)
            || blank($purchase->supplier_cai)
        );

        $category = $purchase->expense_category ?? ExpenseCategory::Otros;
        $payment = $purchase->payment_method;
        $date = CarbonImmutable::instance($purchase->date);

        return new self(
            reference: $purchase->purchase_number,
            expenseDate: $date,
            categoryLabel: $category->getLabel(),
            categoryValue: $category->value,
            description: $purchase->description ?? $purchase->notes ?? '—',
            providerName: $purchase->supplier?->name,
            providerRtn: $purchase->supplier?->rtn,
            providerInvoiceNumber: $purchase->supplier_invoice_number,
            providerInvoiceCai: $purchase->supplier_cai,
            providerInvoiceDate: $isInvoice ? $date : null,
            amountBase: (float) $purchase->subtotal,
            isvAmount: (float) $purchase->isv,
            amountTotal: (float) $purchase->total,
            isIsvDeductible: $isInvoice,
            deducibleIncompleto: $incompleto,
            paymentMethodLabel: $payment?->getLabel() ?? 'No registrado',
            paymentMethodValue: $payment?->value ?? 'no_registrado',
            affectsCash: $payment?->affectsCashBalance() ?? false,
            establishmentName: $purchase->establishment?->name ?? '—',
            userName: $purchase->createdBy?->name ?? '—',
        );
    }

    /**
     * Entrada a partir de la comisión de tarjeta de una venta. No es
     * deducible ni sale de caja (la retiene el banco del depósito).
     *
     * La venta debe venir con `establishment` y `createdBy` cargados.
     */
    public static function fromCardFee(Sale $sale): self
    {
        $payment = $sale->payment_method instanceof PaymentMethod
            ? $sale->payment_method
            : PaymentMethod::tryFrom((string) $sale->payment_method);
        $fee = (float) $sale->card_fee_amount;

        return new self(
            reference: $sale->sale_number ?? "#{$sale->id}",
            expenseDate: CarbonImmutable::instance($sale->date),
            categoryLabel: ExpenseCategory::ComisionesBancarias->getLabel(),
            categoryValue: ExpenseCategory::ComisionesBancarias->value,
            description: sprintf(
                'Comisión por pago con %s en venta %s',
                $payment?->getLabel() ?? 'tarjeta',
                $sale->sale_number ?? "#{$sale->id}",
            ),
            providerName: null,
            providerRtn: null,
            providerInvoiceNumber: null,
            providerInvoiceCai: null,
            providerInvoiceDate: null,
            amountBase: $fee,
            isvAmount: 0.0,
            amountTotal: $fee,
            isIsvDeductible: false,
            deducibleIncompleto: false,
            paymentMethodLabel: $payment?->getLabel() ?? 'Tarjeta',
            paymentMethodValue: $payment?->value ?? 'tarjeta',
            affectsCash: false,
            establishmentName: $sale->establishment?->name ?? '—',
            userName: $sale->createdBy?->name ?? '—',
        );
    }

    /** Etiqueta para columna "Deducible" del Excel y la tabla. */
    public function deducibleLabel(): string
    {
        return $this->isIsvDeductible ? 'Sí' : 'No';
    }

    /**
     * Etiqueta humana del estado fiscal — tres valores posibles:
     *   - "No deducible"            → Recibo Interno o comisión: sin crédito fiscal
     *   - "Completo"                → factura con sus datos en orden
     *   - "Deducible incompleto"    → factura a la que le falta RTN/número/CAI
     */
    public function fiscalStatusLabel(): string
    {
        if (! $this->isIsvDeductible) {
            return 'No deducible';
        }

        return $this->deducibleIncompleto ? 'Deducible incompleto' : 'Completo';
    }
}

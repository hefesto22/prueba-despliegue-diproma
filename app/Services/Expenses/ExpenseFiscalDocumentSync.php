<?php

declare(strict_types=1);

namespace App\Services\Expenses;

use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Purchases\Exceptions\FacturaYaRegistradaException;
use App\Services\Purchases\Exceptions\MontosDocumentoInvalidosException;
use App\Services\Purchases\PurchaseDocumentAmounts;
use App\Services\Purchases\PurchaseService;
use Illuminate\Support\Facades\DB;

/**
 * Mantiene la copia en Compras de un gasto con factura con CAI (Fase 1b).
 *
 * ─── Por qué existe ─────────────────────────────────────────────────────────
 * El crédito fiscal de la Declaración ISV sale únicamente del Libro de
 * Compras, que lee `purchases`. Los gastos con factura (papelería, internet,
 * energía, mantenimiento) se registraban solo en `expenses`, así que su ISV
 * nunca llegaba al libro. Pero el gasto tiene que seguir viviendo en Gastos:
 * de ahí salen la Utilidad Neta y el descuento del cajón. La solución es que
 * el gasto sea la FUENTE DE VERDAD y la compra una copia derivada:
 *
 *   - deducible sin compra vigente     → crea la compra y la confirma
 *   - deducible con compra vigente     → la actualiza con los datos del gasto
 *   - no deducible con compra vigente  → la anula (sale del libro)
 *
 * La compra no se edita ni se anula desde Compras (ver ViewPurchase).
 *
 * ─── Gastos heredados ───────────────────────────────────────────────────────
 * Los gastos marcados deducibles antes de esta fase no tienen importe gravado
 * y se dejan como están (Expense::isLegacyDeductible). Cargarles el gravado es
 * la forma explícita de mandarlos al libro.
 *
 * ─── Protecciones ───────────────────────────────────────────────────────────
 *   - Lock sobre el gasto: dos guardados simultáneos no crean dos compras.
 *   - Factura ya vigente en Compras → FacturaYaRegistradaException.
 *   - Período del documento ya declarado → el PurchaseObserver lanza
 *     PeriodoFiscalCerradoException, igual que en cualquier compra.
 * Cualquiera de ellas revierte también el gasto: o entran los dos o ninguno.
 */
class ExpenseFiscalDocumentSync
{
    /**
     * Columnas de `purchases` → campo del gasto donde se muestra el error.
     * El exento no es un campo del gasto (se deriva del total).
     */
    private const EXPENSE_FIELD_FOR = [
        'taxable_total' => 'taxable_amount',
        'isv' => 'isv_amount',
        'exempt_total' => 'amount_total',
    ];

    public function __construct(
        private readonly PurchaseService $purchases,
    ) {}

    /**
     * @throws FacturaYaRegistradaException
     * @throws MontosDocumentoInvalidosException con `field` = campo del gasto
     * @throws \App\Services\FiscalPeriods\Exceptions\PeriodoFiscalCerradoException
     */
    public function sync(Expense $expense): void
    {
        DB::transaction(function () use ($expense) {
            // Serializa guardados concurrentes del mismo gasto.
            Expense::query()->whereKey($expense->getKey())->lockForUpdate()->first(['id']);

            $current = $expense->purchases()
                ->where('status', '!=', PurchaseStatus::Anulada->value)
                ->lockForUpdate()
                ->first();

            if (! $expense->is_isv_deductible || $expense->isLegacyDeductible()) {
                if ($current !== null) {
                    $this->purchases->cancel($current);
                }

                return;
            }

            $attributes = $this->purchaseAttributes($expense);
            $this->assertNotRegisteredElsewhere($attributes, ignorePurchaseId: $current?->id);

            if ($current !== null) {
                $current->fill($attributes)->save();

                return;
            }

            $purchase = Purchase::create([
                ...$attributes,
                'expense_id' => $expense->id,
                'status' => PurchaseStatus::Borrador,
                'credit_days' => 0,
            ]);

            $this->purchases->confirm($purchase);
        });
    }

    /**
     * Montos del documento a partir de los datos del gasto: el exento es lo
     * que queda del total después del gravado y su ISV.
     *
     * Público y estático para que los formularios de gasto validen con las
     * mismas reglas que se aplican al guardar.
     *
     * @throws MontosDocumentoInvalidosException con `field` = campo del gasto
     */
    public static function amountsFor(float $amountTotal, float $taxable, float $isv): PurchaseDocumentAmounts
    {
        $exempt = round($amountTotal - $taxable - $isv, 2);

        if ($exempt < 0) {
            throw new MontosDocumentoInvalidosException(
                'taxable_amount',
                'El importe gravado más el ISV no puede ser mayor que el monto total del gasto.',
            );
        }

        try {
            return PurchaseDocumentAmounts::forDocument(SupplierDocumentType::Factura, $taxable, $exempt, $isv);
        } catch (MontosDocumentoInvalidosException $e) {
            throw new MontosDocumentoInvalidosException(
                self::EXPENSE_FIELD_FOR[$e->field] ?? 'amount_total',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseAttributes(Expense $expense): array
    {
        $amounts = self::amountsFor(
            (float) $expense->amount_total,
            (float) $expense->taxable_amount,
            (float) ($expense->isv_amount ?? 0),
        );

        return [
            'establishment_id' => $expense->establishment_id,
            'supplier_id' => $this->resolveSupplier($expense)->id,
            'document_type' => SupplierDocumentType::Factura,
            'supplier_invoice_number' => $expense->provider_invoice_number,
            'supplier_cai' => $expense->provider_invoice_cai,
            // El libro va por la fecha del documento; si no se anotó, la del gasto.
            'date' => $expense->provider_invoice_date ?? $expense->expense_date,
            'notes' => "Generada desde el gasto #{$expense->id}: {$expense->description}",
            ...$amounts->toAttributes(),
        ];
    }

    /**
     * Proveedor por RTN; si no existe se crea con el nombre anotado en el gasto.
     *
     * withTrashed(): el RTN es único a nivel de BD incluso entre proveedores
     * eliminados. Si el RTN pertenece a uno eliminado se restaura, porque la
     * compra necesita un proveedor visible en el Libro de Compras.
     */
    private function resolveSupplier(Expense $expense): Supplier
    {
        $supplier = Supplier::withTrashed()->where('rtn', $expense->provider_rtn)->first();

        if ($supplier === null) {
            return Supplier::create([
                'name' => $expense->provider_name ?: "Proveedor RTN {$expense->provider_rtn}",
                'rtn' => $expense->provider_rtn,
                'credit_days' => 0,
                'is_active' => true,
                'notes' => "Creado automáticamente al registrar el gasto #{$expense->id}.",
            ]);
        }

        if ($supplier->trashed()) {
            $supplier->restore();
        }

        return $supplier;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws FacturaYaRegistradaException
     */
    private function assertNotRegisteredElsewhere(array $attributes, ?int $ignorePurchaseId): void
    {
        $existing = Purchase::query()
            ->where('supplier_id', $attributes['supplier_id'])
            ->where('document_type', SupplierDocumentType::Factura->value)
            ->where('supplier_invoice_number', $attributes['supplier_invoice_number'])
            ->where('status', '!=', PurchaseStatus::Anulada->value)
            ->when($ignorePurchaseId !== null, fn ($query) => $query->whereKeyNot($ignorePurchaseId))
            ->value('purchase_number');

        if ($existing !== null) {
            throw new FacturaYaRegistradaException($attributes['supplier_invoice_number'], $existing);
        }
    }
}

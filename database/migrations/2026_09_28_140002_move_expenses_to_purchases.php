<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migración de DATOS: el módulo de Gastos se retira y su historia pasa a
 * Compras ("todo en Compras", aprobado 2026-09-28).
 *
 * Qué hace, todo en una sola transacción (o entra todo o nada):
 *
 *   1. Comisiones de tarjeta (gastos automáticos con sale_id): se suman en
 *      sales.card_fee_amount de la venta que las originó. No son compras.
 *
 *   2. Cada gasto restante se convierte en una compra:
 *        - kind = gasto, con su categoría, descripción, fecha, sucursal y
 *          forma de pago.
 *        - Documento RECIBO INTERNO (99), confirmada y pagada, monto completo
 *          como exento. Recibo Interno a propósito, aunque el gasto trajera
 *          factura: así el Libro de Compras de todos los meses pasados
 *          (incluidos los ya declarados al SAR) queda idéntico — decisión
 *          "solo de aquí en adelante". Los datos de la factura (RTN,
 *          número, CAI, ISV) se conservan en las notas.
 *        - Proveedor: el registrado con ese RTN si existe; si no, el genérico
 *          "Varios / Sin identificar" (el nombre anotado va en las notas).
 *        - Los movimientos de caja del gasto (efectivo) se re-enlazan a la
 *          compra por la referencia polimórfica de cash_movements. No se
 *          crean movimientos nuevos: el dinero ya salió en su momento.
 *
 *   3. La Utilidad Neta histórica no cambia: resta el subtotal de las compras
 *      tipo gasto (en un Recibo Interno subtotal = total pagado, igual que el
 *      monto del gasto) más las comisiones de las ventas.
 *
 * Se escribe con DB::table() y sin clases de la app, por dos razones:
 *   - La migración no debe romperse si mañana cambia un modelo o servicio
 *     (ya pasó con PurchaseTotalsCalculator).
 *   - PurchaseObserver bloquea crear compras en períodos declarados. Aquí es
 *     legítimo saltarlo: un Recibo Interno no entra al Libro de Compras, así
 *     que ningún libro declarado cambia.
 *
 * La tabla `expenses` NO se toca: queda como respaldo y se elimina en un
 * deploy posterior. purchases.legacy_expense_id guarda el rastro.
 */
return new class extends Migration
{
    private const GENERIC_SUPPLIER_NAME = 'Varios / Sin identificar';

    private const CARD_FEE_CATEGORY = 'comisiones_bancarias';

    private const MAX_INTERNAL_RECEIPTS_PER_DAY = 9999;

    private ?int $purchaseSequence = null;

    /** @var array<string, int> */
    private array $internalReceiptSequence = [];

    public function up(): void
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }

        DB::transaction(function () {
            $fees = $this->moveCardFeesToSales();
            $moved = $this->moveExpensesToPurchases();

            echo "  → Comisiones de tarjeta pasadas a sus ventas: {$fees}\n";
            echo "  → Gastos convertidos en compras tipo gasto: {$moved}\n";
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }

        DB::transaction(function () {
            $migrated = DB::table('purchases')->whereNotNull('legacy_expense_id')->pluck('id');

            DB::table('cash_movements')
                ->where('reference_type', 'App\\Models\\Purchase')
                ->whereIn('reference_id', $migrated)
                ->update(['reference_type' => null, 'reference_id' => null]);

            DB::table('purchases')->whereIn('id', $migrated)->delete();

            // Solo las ventas cuya comisión vino de un gasto: las comisiones
            // registradas después de la migración no tienen gasto que las respalde.
            DB::table('sales')
                ->whereIn('id', DB::table('expenses')
                    ->where('category', self::CARD_FEE_CATEGORY)
                    ->whereNotNull('sale_id')
                    ->select('sale_id'))
                ->update(['card_fee_amount' => 0]);
        });
    }

    private function moveCardFeesToSales(): int
    {
        $fees = DB::table('expenses')
            ->where('category', self::CARD_FEE_CATEGORY)
            ->whereNotNull('sale_id')
            ->groupBy('sale_id')
            ->selectRaw('sale_id, SUM(amount_total) as fee')
            ->get();

        foreach ($fees as $row) {
            DB::table('sales')
                ->where('id', $row->sale_id)
                ->update(['card_fee_amount' => round((float) $row->fee, 2)]);
        }

        return $fees->count();
    }

    private function moveExpensesToPurchases(): int
    {
        $expenses = DB::table('expenses')
            ->where(function ($query) {
                $query->where('category', '!=', self::CARD_FEE_CATEGORY)
                    ->orWhereNull('sale_id');
            })
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get();

        if ($expenses->isEmpty()) {
            return 0;
        }

        $genericSupplierId = DB::table('suppliers')
            ->where('is_generic', true)
            ->where('name', self::GENERIC_SUPPLIER_NAME)
            ->value('id');

        if ($genericSupplierId === null) {
            throw new RuntimeException('No existe el proveedor genérico "'.self::GENERIC_SUPPLIER_NAME.'": no se pueden migrar los gastos.');
        }

        foreach ($expenses as $expense) {
            $date = CarbonImmutable::parse($expense->expense_date);
            $amount = round((float) $expense->amount_total, 2);

            $purchaseId = DB::table('purchases')->insertGetId([
                'purchase_number' => $this->nextPurchaseNumber(),
                'establishment_id' => $expense->establishment_id,
                'supplier_id' => $this->supplierFor($expense->provider_rtn) ?? $genericSupplierId,
                'supplier_invoice_number' => $this->nextInternalReceiptNumber($date),
                'supplier_cai' => null,
                'document_type' => '99',
                'kind' => 'gasto',
                'expense_category' => $expense->category,
                'description' => mb_substr((string) $expense->description, 0, 500),
                'date' => $date->toDateString(),
                'due_date' => null,
                'status' => 'confirmada',
                'payment_status' => 'pagada',
                'payment_method' => $expense->payment_method,
                'subtotal' => $amount,
                'taxable_total' => 0,
                'exempt_total' => $amount,
                'isv' => 0,
                'total' => $amount,
                'credit_days' => 0,
                'notes' => $this->notesFor($expense),
                'legacy_expense_id' => $expense->id,
                'created_by' => $expense->created_by ?? $expense->user_id,
                'updated_by' => $expense->updated_by,
                'created_at' => $expense->created_at,
                'updated_at' => $expense->updated_at,
            ]);

            DB::table('cash_movements')
                ->where('expense_id', $expense->id)
                ->update([
                    'reference_type' => 'App\\Models\\Purchase',
                    'reference_id' => $purchaseId,
                ]);
        }

        return $expenses->count();
    }

    private function supplierFor(?string $rtn): ?int
    {
        if (blank($rtn)) {
            return null;
        }

        return DB::table('suppliers')
            ->where('rtn', $rtn)
            ->whereNull('deleted_at')
            ->value('id');
    }

    /**
     * Mismo formato que Purchase::generateNextNumber(): COMP-{año actual}-NNNNN.
     */
    private function nextPurchaseNumber(): string
    {
        $prefix = 'COMP-'.now()->year.'-';

        if ($this->purchaseSequence === null) {
            $last = DB::table('purchases')
                ->where('purchase_number', 'like', $prefix.'%')
                ->orderByDesc('purchase_number')
                ->value('purchase_number');

            $this->purchaseSequence = $last ? (int) substr($last, -5) : 0;
        }

        return $prefix.str_pad((string) ++$this->purchaseSequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Mismo formato que InternalReceiptNumberGenerator: RI-YYYYMMDD-NNNN,
     * continuando la secuencia de ese día si ya había Recibos Internos.
     */
    private function nextInternalReceiptNumber(CarbonImmutable $date): string
    {
        $prefix = 'RI-'.$date->format('Ymd').'-';

        if (! isset($this->internalReceiptSequence[$prefix])) {
            $last = DB::table('purchases')
                ->where('supplier_invoice_number', 'like', $prefix.'%')
                ->orderByDesc('supplier_invoice_number')
                ->value('supplier_invoice_number');

            $this->internalReceiptSequence[$prefix] = $last ? (int) substr($last, -4) : 0;
        }

        $next = ++$this->internalReceiptSequence[$prefix];

        if ($next > self::MAX_INTERNAL_RECEIPTS_PER_DAY) {
            throw new RuntimeException("Más de 9999 Recibos Internos el {$date->toDateString()}: no se puede asignar correlativo.");
        }

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Conserva en las notas todo lo que el gasto tenía y la compra-RI no
     * tiene columna para guardar.
     */
    private function notesFor(object $expense): string
    {
        $parts = ["Migrado del gasto #{$expense->id} al retirar el módulo de Gastos (2026-09-28)."];

        if (filled($expense->provider_name)) {
            $parts[] = "Proveedor anotado: {$expense->provider_name}.";
        }

        if (filled($expense->provider_rtn)) {
            $parts[] = "RTN: {$expense->provider_rtn}.";
        }

        if (filled($expense->provider_invoice_number)) {
            $parts[] = "Factura: {$expense->provider_invoice_number}.";
        }

        if (filled($expense->provider_invoice_cai)) {
            $parts[] = "CAI: {$expense->provider_invoice_cai}.";
        }

        if (filled($expense->attachment_path ?? null)) {
            $parts[] = "Comprobante adjunto: {$expense->attachment_path}.";
        }

        if (filled($expense->isv_amount) && (float) $expense->isv_amount > 0) {
            $parts[] = 'ISV desglosado: L '.number_format((float) $expense->isv_amount, 2)
                .($expense->is_isv_deductible ? ' (estaba marcado deducible; no entra al Libro de Compras).' : '.');
        }

        return implode(' ', $parts);
    }
};

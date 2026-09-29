<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseKind;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Models\Establishment;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchases\InternalReceiptNumberGenerator;
use App\Services\Purchases\PurchaseDocumentAmounts;
use App\Services\Purchases\PurchaseService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Genera gastos NO-efectivo del período histórico como compras tipo Gasto.
 *
 * Desde "todo en Compras" (2026-09-28) los gastos son compras con
 * kind = gasto. Los gastos en EFECTIVO se generan en HistoricalOperationsSeeder
 * (necesitan caja abierta); este seeder cubre los que no pasan por el cajón:
 *
 *   - Alquiler mensual del local — transferencia, factura con CAI.
 *   - Servicios básicos (luz, agua, internet) — transferencia.
 *   - Mantenimiento extraordinario — transferencia/cheque.
 *
 * Documento: si el gasto trae factura con CAI y número SAR → Factura (entra al
 * Libro de Compras con su ISV). Si no (p. ej. ENEE sin CAI, gastos sin
 * factura) → Recibo Interno.
 *
 * Atribución: created_by = Carlos (admin).
 *
 * Idempotencia: omite un gasto si ya existe una compra tipo Gasto con la misma
 * fecha y concepto.
 *
 * Pre-requisitos:
 *   - OperationalUsersSeeder (Carlos)
 *   - CompanySettingSeeder (Matriz)
 */
class HistoricalExpensesSeeder extends Seeder
{
    public function run(): void
    {
        $carlos = User::where('email', 'carlos.mendoza@diproma.hn')->firstOrFail();
        $matriz = Establishment::where('is_main', true)->firstOrFail();

        $gastos = [
            // ─── Alquiler ────────────────────────────────────────────────
            [
                'expense_date' => '2026-03-01',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::Transferencia,
                'amount_total' => 18000.00,
                'isv_amount' => 2347.83,
                'is_isv_deductible' => true,
                'description' => 'Alquiler local Boulevard principal — marzo 2026',
                'provider_name' => 'Inmobiliaria Reyes y Cía.',
                'provider_rtn' => '08019988899900',
                'provider_invoice_number' => '001-001-01-00033421',
                'provider_invoice_cai' => 'CC1122-DD3344-EE5566-FF7788-AA9900-B1',
                'provider_invoice_date' => '2026-03-01',
            ],
            [
                'expense_date' => '2026-04-01',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::Transferencia,
                'amount_total' => 18000.00,
                'isv_amount' => 2347.83,
                'is_isv_deductible' => true,
                'description' => 'Alquiler local Boulevard principal — abril 2026',
                'provider_name' => 'Inmobiliaria Reyes y Cía.',
                'provider_rtn' => '08019988899900',
                'provider_invoice_number' => '001-001-01-00033587',
                'provider_invoice_cai' => 'CC1122-DD3344-EE5566-FF7788-AA9900-B1',
                'provider_invoice_date' => '2026-04-01',
            ],

            // ─── Energía Eléctrica (ENEE) ────────────────────────────────
            [
                'expense_date' => '2026-03-08',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::Transferencia,
                'amount_total' => 4250.00,
                'isv_amount' => 554.35,
                'is_isv_deductible' => true,
                'description' => 'Energía eléctrica ENEE — febrero 2026',
                'provider_name' => 'Empresa Nacional de Energía Eléctrica (ENEE)',
                'provider_rtn' => '08019999999900',
                'provider_invoice_number' => '202602-008765432',
                'provider_invoice_cai' => null,
                'provider_invoice_date' => '2026-03-05',
            ],
            [
                'expense_date' => '2026-04-08',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::Transferencia,
                'amount_total' => 4580.00,
                'isv_amount' => 597.39,
                'is_isv_deductible' => true,
                'description' => 'Energía eléctrica ENEE — marzo 2026',
                'provider_name' => 'Empresa Nacional de Energía Eléctrica (ENEE)',
                'provider_rtn' => '08019999999900',
                'provider_invoice_number' => '202603-009123456',
                'provider_invoice_cai' => null,
                'provider_invoice_date' => '2026-04-05',
            ],

            // ─── Agua (Aguas de SPS) ─────────────────────────────────────
            [
                'expense_date' => '2026-03-12',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::Transferencia,
                'amount_total' => 850.00,
                'isv_amount' => null,
                'is_isv_deductible' => false,
                'description' => 'Agua potable — febrero 2026',
                'provider_name' => 'Aguas de San Pedro',
                'provider_rtn' => '08019988877700',
                'provider_invoice_number' => '202602-1122334',
                'provider_invoice_cai' => null,
                'provider_invoice_date' => '2026-03-10',
            ],
            [
                'expense_date' => '2026-04-12',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::Transferencia,
                'amount_total' => 875.00,
                'isv_amount' => null,
                'is_isv_deductible' => false,
                'description' => 'Agua potable — marzo 2026',
                'provider_name' => 'Aguas de San Pedro',
                'provider_rtn' => '08019988877700',
                'provider_invoice_number' => '202603-1124501',
                'provider_invoice_cai' => null,
                'provider_invoice_date' => '2026-04-10',
            ],

            // ─── Internet (Tigo Business) ────────────────────────────────
            [
                'expense_date' => '2026-03-15',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::TarjetaCredito,
                'amount_total' => 2800.00,
                'isv_amount' => 365.22,
                'is_isv_deductible' => true,
                'description' => 'Internet fibra empresarial — marzo 2026',
                'provider_name' => 'Tigo Business Honduras',
                'provider_rtn' => '08019966554400',
                'provider_invoice_number' => '001-001-01-00876543',
                'provider_invoice_cai' => 'AA9988-BB7766-CC5544-DD3322-EE1100-F1',
                'provider_invoice_date' => '2026-03-15',
            ],
            [
                'expense_date' => '2026-04-15',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::TarjetaCredito,
                'amount_total' => 2800.00,
                'isv_amount' => 365.22,
                'is_isv_deductible' => true,
                'description' => 'Internet fibra empresarial — abril 2026',
                'provider_name' => 'Tigo Business Honduras',
                'provider_rtn' => '08019966554400',
                'provider_invoice_number' => '001-001-01-00879221',
                'provider_invoice_cai' => 'AA9988-BB7766-CC5544-DD3322-EE1100-F1',
                'provider_invoice_date' => '2026-04-15',
            ],

            // ─── Mantenimiento extraordinario ────────────────────────────
            [
                'expense_date' => '2026-03-20',
                'category' => ExpenseCategory::Mantenimiento,
                'payment_method' => PaymentMethod::Transferencia,
                'amount_total' => 3500.00,
                'isv_amount' => 456.52,
                'is_isv_deductible' => true,
                'description' => 'Mantenimiento aire acondicionado — limpieza y carga de gas',
                'provider_name' => 'Climatec Servicios',
                'provider_rtn' => '08019977711200',
                'provider_invoice_number' => '001-001-01-00012876',
                'provider_invoice_cai' => 'BB2233-CC4455-DD6677-EE8899-FF0011-A1',
                'provider_invoice_date' => '2026-03-20',
            ],
            [
                'expense_date' => '2026-04-10',
                'category' => ExpenseCategory::Mantenimiento,
                'payment_method' => PaymentMethod::Cheque,
                'amount_total' => 1850.00,
                'isv_amount' => 241.30,
                'is_isv_deductible' => true,
                'description' => 'Reparación cerradura puerta principal — visita técnica',
                'provider_name' => 'Cerrajería Don Jorge',
                'provider_rtn' => '08019944422100',
                'provider_invoice_number' => '001-001-01-00003121',
                'provider_invoice_cai' => 'DD4455-EE6677-FF8899-AA0011-BB2233-C1',
                'provider_invoice_date' => '2026-04-10',
            ],
        ];

        Auth::login($carlos);

        $created = 0;
        foreach ($gastos as $data) {
            $exists = Purchase::query()
                ->gastos()
                ->whereDate('date', $data['expense_date'])
                ->where('description', $data['description'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::transaction(function () use ($data, $matriz, $carlos) {
                $purchase = Purchase::create([
                    ...$this->documentFields($data),
                    'establishment_id' => $matriz->id,
                    'kind' => PurchaseKind::Gasto,
                    'expense_category' => $data['category'],
                    'description' => $data['description'],
                    'payment_method' => $data['payment_method'],
                    'date' => $data['provider_invoice_date'] ?? $data['expense_date'],
                    'credit_days' => 0,
                    'status' => PurchaseStatus::Borrador,
                    'created_by' => $carlos->id,
                ]);

                app(PurchaseService::class)->confirm($purchase);
            });

            $created++;
        }

        $this->command?->info(sprintf(
            'Gastos no-efectivo creados en Compras: %d nuevos (de %d totales)',
            $created,
            count($gastos),
        ));
    }

    /**
     * Documento y montos: Factura si trae CAI y número SAR, si no Recibo
     * Interno. En la factura el gravado es lo que queda del total tras el ISV.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function documentFields(array $data): array
    {
        $total = (float) $data['amount_total'];
        $isv = (float) ($data['isv_amount'] ?? 0);

        $isInvoice = filled($data['provider_invoice_cai'] ?? null)
            && preg_match('/^\d{3}-\d{3}-\d{2}-\d{8}$/', (string) ($data['provider_invoice_number'] ?? '')) === 1;

        if ($isInvoice) {
            $supplier = Supplier::firstOrCreate(
                ['rtn' => $data['provider_rtn']],
                ['name' => $data['provider_name'], 'is_active' => true, 'credit_days' => 0],
            );

            return [
                'supplier_id' => $supplier->id,
                'document_type' => SupplierDocumentType::Factura,
                'supplier_invoice_number' => $data['provider_invoice_number'],
                'supplier_cai' => $data['provider_invoice_cai'],
                ...PurchaseDocumentAmounts::forDocument(SupplierDocumentType::Factura, $total - $isv, 0, $isv)->toAttributes(),
            ];
        }

        return [
            'supplier_id' => Supplier::forInternalReceipts()->id,
            'document_type' => SupplierDocumentType::ReciboInterno,
            'supplier_invoice_number' => app(InternalReceiptNumberGenerator::class)->next(Carbon::parse($data['expense_date'])),
            'supplier_cai' => null,
            'notes' => filled($data['provider_name'] ?? null) ? "Proveedor: {$data['provider_name']}" : null,
            ...PurchaseDocumentAmounts::forDocument(SupplierDocumentType::ReciboInterno, 0, $total, 0)->toAttributes(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Expenses;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseKind;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\Expenses\ExpensesMonthlyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cubre la construcción del DTO `ExpensesMonthlyReport` desde "todo en
 * Compras" (2026-09-28): los gastos son compras tipo Gasto confirmadas y las
 * comisiones de tarjeta viven en las ventas.
 *
 *   - Período correcto: solo gastos del year+month solicitado.
 *   - Solo compras tipo Gasto CONFIRMADAS (no mercadería, borradores ni anuladas).
 *   - Filtro por sucursal: aislamiento entre sucursales.
 *   - Crédito fiscal: solo facturas, suma de su ISV. Recibo Interno no deduce.
 *   - Facturas incompletas: sin RTN del proveedor o sin CAI cuentan como alerta.
 *   - Comisiones de tarjeta: entran como Comisiones bancarias, sin caja ni ISV.
 *   - Buckets: byCategory / byPaymentMethod / byEstablishment ordenados por total desc.
 *   - Impacto en caja: cashCount/cashTotal solo para Efectivo.
 */
class ExpensesMonthlyReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpensesMonthlyReportService $service;

    private CompanySetting $company;

    private Establishment $matriz;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');
        $this->company = CompanySetting::factory()->create([
            'rtn' => '08011999123456',
        ]);
        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create(['name' => 'Matriz Tegucigalpa']);

        $this->service = app(ExpensesMonthlyReportService::class);
    }

    /**
     * Gasto con Recibo Interno (sin ISV) — el caso más común de caja chica.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeGasto(float $total = 100.00, array $overrides = []): Purchase
    {
        return Purchase::factory()
            ->forEstablishment($this->matriz)
            ->create(array_merge([
                'kind' => PurchaseKind::Gasto,
                'status' => PurchaseStatus::Confirmada,
                'expense_category' => ExpenseCategory::Otros,
                'description' => 'Test',
                'payment_method' => PaymentMethod::Efectivo,
                'date' => '2026-04-15',
                'document_type' => SupplierDocumentType::ReciboInterno,
                'supplier_cai' => null,
                'subtotal' => $total,
                'taxable_total' => 0,
                'exempt_total' => $total,
                'isv' => 0,
                'total' => $total,
            ], $overrides));
    }

    /**
     * Gasto con Factura: el ISV cuenta como crédito fiscal.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeGastoConFactura(float $taxable, array $overrides = []): Purchase
    {
        return Purchase::factory()
            ->forEstablishment($this->matriz)
            ->withTotals($taxable)
            ->create(array_merge([
                'kind' => PurchaseKind::Gasto,
                'status' => PurchaseStatus::Confirmada,
                'expense_category' => ExpenseCategory::Servicios,
                'description' => 'Factura test',
                'payment_method' => PaymentMethod::Transferencia,
                'date' => '2026-04-15',
                'document_type' => SupplierDocumentType::Factura,
            ], $overrides));
    }

    // ─── Período y fuentes ────────────────────────────────────

    public function test_build_solo_incluye_gastos_del_periodo_solicitado(): void
    {
        // Abril 2026 — debe incluirse.
        $this->makeGasto(200.00, ['date' => '2026-04-01']);
        $this->makeGasto(300.00, ['date' => '2026-04-30']);

        // Marzo 2026 y Mayo 2026 — deben excluirse.
        $this->makeGasto(999.00, ['date' => '2026-03-31']);
        $this->makeGasto(999.00, ['date' => '2026-05-01']);

        $report = $this->service->build(year: 2026, month: 4);

        $this->assertSame(2, $report->summary->gastosCount);
        $this->assertSame(500.00, $report->summary->gastosTotal);
        $this->assertCount(2, $report->entries);
    }

    public function test_build_ignora_mercaderia_borradores_y_anuladas(): void
    {
        $this->makeGasto(100.00);

        $this->makeGasto(999.00, ['kind' => PurchaseKind::Mercaderia, 'expense_category' => null]);
        $this->makeGasto(999.00, ['status' => PurchaseStatus::Borrador]);
        $this->makeGasto(999.00, ['status' => PurchaseStatus::Anulada]);

        $report = $this->service->build(year: 2026, month: 4);

        $this->assertSame(1, $report->summary->gastosCount);
        $this->assertSame(100.00, $report->summary->gastosTotal);
    }

    // ─── Filtro por sucursal ──────────────────────────────────

    public function test_build_con_establishment_id_aisla_solo_la_sucursal_solicitada(): void
    {
        $sucursalB = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create(['is_main' => false, 'name' => 'Sucursal Catacamas']);

        $this->makeGasto(100.00);
        $this->makeGasto(200.00);
        $this->makeGasto(999.00, ['establishment_id' => $sucursalB->id]);

        $reportMatriz = $this->service->build(year: 2026, month: 4, establishmentId: $this->matriz->id);

        $this->assertSame(2, $reportMatriz->summary->gastosCount);
        $this->assertSame(300.00, $reportMatriz->summary->gastosTotal);

        $reportTodas = $this->service->build(year: 2026, month: 4);
        $this->assertSame(3, $reportTodas->summary->gastosCount);
        $this->assertSame(1299.00, $reportTodas->summary->gastosTotal);
    }

    // ─── Crédito fiscal: solo facturas ────────────────────────

    public function test_credito_fiscal_solo_suma_isv_de_facturas(): void
    {
        // Factura gravada 100 → ISV 15, total 115.
        $this->makeGastoConFactura(100.00);
        // Factura gravada 200 → ISV 30, total 230.
        $this->makeGastoConFactura(200.00);
        // Recibo Interno: no deduce aunque el monto sea igual.
        $this->makeGasto(115.00);

        $report = $this->service->build(year: 2026, month: 4);

        $this->assertSame(2, $report->summary->deduciblesCount);
        $this->assertSame(345.00, $report->summary->deduciblesTotal);
        $this->assertSame(45.00, $report->summary->creditoFiscalDeducible);
        $this->assertSame(1, $report->summary->noDeduciblesCount);
        $this->assertSame(115.00, $report->summary->noDeduciblesTotal);
    }

    public function test_facturas_sin_rtn_o_cai_se_cuentan_como_incompletas(): void
    {
        // Completa.
        $this->makeGastoConFactura(100.00);

        // Proveedor sin RTN.
        $sinRtn = Supplier::factory()->create(['rtn' => null]);
        $this->makeGastoConFactura(100.00, ['supplier_id' => $sinRtn->id]);

        // Sin CAI.
        $this->makeGastoConFactura(100.00, ['supplier_cai' => null]);

        $report = $this->service->build(year: 2026, month: 4);

        $this->assertSame(3, $report->summary->deduciblesCount);
        $this->assertSame(2, $report->summary->deduciblesIncompletosCount);
        $this->assertTrue($report->summary->hasIncompleteWarnings());
    }

    // ─── Comisiones de tarjeta ────────────────────────────────

    public function test_comisiones_de_tarjeta_de_las_ventas_entran_como_gasto_bancario(): void
    {
        $sale = Sale::factory()->forEstablishment($this->matriz)->completada()->create([
            'date' => '2026-04-20',
            'payment_method' => PaymentMethod::TarjetaCredito,
            'total' => 1000.00,
            'card_fee_amount' => 34.00,
        ]);

        // Venta sin comisión y comisión de otro mes: no entran.
        Sale::factory()->forEstablishment($this->matriz)->completada()->create(['date' => '2026-04-20']);
        Sale::factory()->forEstablishment($this->matriz)->completada()->create([
            'date' => '2026-05-02',
            'card_fee_amount' => 50.00,
        ]);

        $report = $this->service->build(year: 2026, month: 4);

        $this->assertSame(1, $report->summary->gastosCount);
        $this->assertSame(34.00, $report->summary->gastosTotal);
        $this->assertSame(0.0, $report->summary->creditoFiscalDeducible);
        $this->assertSame(0, $report->summary->cashCount);

        $entry = $report->entries->first();
        $this->assertSame(ExpenseCategory::ComisionesBancarias->value, $entry->categoryValue);
        $this->assertSame($sale->sale_number, $entry->reference);
        $this->assertFalse($entry->isIsvDeductible);
    }

    // ─── Buckets ──────────────────────────────────────────────

    public function test_buckets_agrupan_por_categoria_metodo_pago_y_sucursal(): void
    {
        // Combustible × 2: 100 + 200 = 300 (efectivo)
        $this->makeGasto(100.00, ['expense_category' => ExpenseCategory::Combustible]);
        $this->makeGasto(200.00, ['expense_category' => ExpenseCategory::Combustible]);

        // Servicios × 1: 1000 (transferencia)
        $this->makeGasto(1000.00, [
            'expense_category' => ExpenseCategory::Servicios,
            'payment_method' => PaymentMethod::Transferencia,
        ]);

        $report = $this->service->build(year: 2026, month: 4);

        // byCategory: ordenado por total desc → servicios (1000) primero, combustible (300) después.
        $catKeys = array_keys($report->summary->byCategory);
        $this->assertSame(ExpenseCategory::Servicios->value, $catKeys[0]);
        $this->assertSame(ExpenseCategory::Combustible->value, $catKeys[1]);
        $this->assertSame(1000.00, $report->summary->byCategory[ExpenseCategory::Servicios->value]['total']);
        $this->assertSame(300.00, $report->summary->byCategory[ExpenseCategory::Combustible->value]['total']);
        $this->assertSame(2, $report->summary->byCategory[ExpenseCategory::Combustible->value]['count']);

        $this->assertSame(1000.00, $report->summary->byPaymentMethod[PaymentMethod::Transferencia->value]['total']);
        $this->assertSame(300.00, $report->summary->byPaymentMethod[PaymentMethod::Efectivo->value]['total']);

        $this->assertCount(1, $report->summary->byEstablishment);
        $this->assertSame(1300.00, $report->summary->byEstablishment['Matriz Tegucigalpa']['total']);
    }

    // ─── Impacto en caja: solo Efectivo ───────────────────────

    public function test_cashtotal_solo_acumula_gastos_en_efectivo(): void
    {
        $this->makeGasto(100.00, ['payment_method' => PaymentMethod::Efectivo]);
        $this->makeGasto(50.00, ['payment_method' => PaymentMethod::Efectivo]);

        // Tarjeta + transferencia + cheque (NO afectan caja)
        $this->makeGasto(200.00, ['payment_method' => PaymentMethod::TarjetaCredito]);
        $this->makeGasto(300.00, ['payment_method' => PaymentMethod::Transferencia]);
        $this->makeGasto(400.00, ['payment_method' => PaymentMethod::Cheque]);

        $report = $this->service->build(year: 2026, month: 4);

        $this->assertSame(2, $report->summary->cashCount);
        $this->assertSame(150.00, $report->summary->cashTotal);
        $this->assertSame(3, $report->summary->nonCashCount);
        $this->assertSame(900.00, $report->summary->nonCashTotal);
    }
}

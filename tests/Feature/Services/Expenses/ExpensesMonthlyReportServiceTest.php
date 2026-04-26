<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Expenses;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\Expense;
use App\Models\User;
use App\Services\Expenses\ExpensesMonthlyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cubre la construcción del DTO `ExpensesMonthlyReport`:
 *
 *   - Período correcto: solo gastos del year+month solicitado.
 *   - Filtro por sucursal: aislamiento entre matrices.
 *   - Sumas globales: count + total bien acumulados.
 *   - Crédito fiscal: solo deducibles, suma de isv_amount.
 *   - Deducibles incompletos: deducible sin RTN/factura/CAI cuenta como alerta.
 *   - Buckets: byCategory / byPaymentMethod / byEstablishment ordenados por total desc.
 *   - Impacto en caja: cashCount/cashTotal solo para Efectivo.
 */
class ExpensesMonthlyReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpensesMonthlyReportService $service;

    private CompanySetting $company;

    private Establishment $matriz;

    private User $user;

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

        $this->user = User::factory()->create();
        $this->service = app(ExpensesMonthlyReportService::class);
    }

    private function makeExpense(array $overrides = []): Expense
    {
        return Expense::factory()
            ->for($this->matriz, 'establishment')
            ->for($this->user, 'user')
            ->create(array_merge([
                'expense_date'      => '2026-04-15',
                'category'          => ExpenseCategory::Otros->value,
                'payment_method'    => PaymentMethod::Efectivo->value,
                'amount_total'      => 100.00,
                'isv_amount'        => null,
                'is_isv_deductible' => false,
                'description'       => 'Test',
            ], $overrides));
    }

    // ─── Período: solo gastos del mes solicitado ──────────────

    public function test_build_solo_incluye_gastos_del_periodo_solicitado(): void
    {
        // Abril 2026 — debe incluirse.
        $this->makeExpense(['expense_date' => '2026-04-10', 'amount_total' => 200.00]);
        $this->makeExpense(['expense_date' => '2026-04-30', 'amount_total' => 300.00]);

        // Marzo 2026 y Mayo 2026 — deben excluirse.
        $this->makeExpense(['expense_date' => '2026-03-31', 'amount_total' => 999.00]);
        $this->makeExpense(['expense_date' => '2026-05-01', 'amount_total' => 999.00]);

        $report = $this->service->build(year: 2026, month: 4);

        $this->assertSame(2, $report->summary->gastosCount);
        $this->assertSame(500.00, $report->summary->gastosTotal);
        $this->assertCount(2, $report->entries);
    }

    // ─── Filtro por sucursal ──────────────────────────────────

    public function test_build_con_establishment_id_aisla_solo_la_sucursal_solicitada(): void
    {
        $sucursalB = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create(['is_main' => false, 'name' => 'Sucursal Catacamas']);

        // Matriz: 2 gastos.
        $this->makeExpense(['amount_total' => 100.00]);
        $this->makeExpense(['amount_total' => 200.00]);

        // Sucursal B: 1 gasto.
        Expense::factory()
            ->for($sucursalB, 'establishment')
            ->for($this->user, 'user')
            ->create([
                'expense_date'      => '2026-04-15',
                'category'          => ExpenseCategory::Otros->value,
                'payment_method'    => PaymentMethod::Efectivo->value,
                'amount_total'      => 999.00,
                'isv_amount'        => null,
                'is_isv_deductible' => false,
                'description'       => 'Sucursal B',
            ]);

        $reportMatriz = $this->service->build(year: 2026, month: 4, establishmentId: $this->matriz->id);

        $this->assertSame(2, $reportMatriz->summary->gastosCount);
        $this->assertSame(300.00, $reportMatriz->summary->gastosTotal);

        $reportTodas = $this->service->build(year: 2026, month: 4);
        $this->assertSame(3, $reportTodas->summary->gastosCount);
        $this->assertSame(1299.00, $reportTodas->summary->gastosTotal);
    }

    // ─── Crédito fiscal: solo deducibles ──────────────────────

    public function test_credito_fiscal_solo_suma_isv_de_gastos_deducibles(): void
    {
        // Deducible con ISV 15.00
        $this->makeExpense([
            'amount_total'      => 115.00,
            'isv_amount'        => 15.00,
            'is_isv_deductible' => true,
            'provider_rtn'      => '08019999999999',
            'provider_invoice_number' => '000-001-01-00000001',
            'provider_invoice_cai'    => 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA-99',
        ]);

        // Deducible con ISV 30.00
        $this->makeExpense([
            'amount_total'      => 230.00,
            'isv_amount'        => 30.00,
            'is_isv_deductible' => true,
            'provider_rtn'      => '08019999999998',
            'provider_invoice_number' => '000-001-01-00000002',
            'provider_invoice_cai'    => 'BBBBBB-BBBBBB-BBBBBB-BBBBBB-BBBBBB-99',
        ]);

        // No deducible con ISV (no debe sumar al crédito fiscal).
        $this->makeExpense([
            'amount_total'      => 115.00,
            'isv_amount'        => 15.00,
            'is_isv_deductible' => false,
        ]);

        $report = $this->service->build(year: 2026, month: 4);

        $this->assertSame(2, $report->summary->deduciblesCount);
        $this->assertSame(345.00, $report->summary->deduciblesTotal);
        $this->assertSame(45.00, $report->summary->creditoFiscalDeducible);
        $this->assertSame(1, $report->summary->noDeduciblesCount);
        $this->assertSame(115.00, $report->summary->noDeduciblesTotal);
    }

    // ─── Deducibles incompletos: alerta ───────────────────────

    public function test_deducibles_sin_rtn_factura_o_cai_se_cuentan_como_incompletos(): void
    {
        // Deducible completo (3 datos presentes).
        $this->makeExpense([
            'amount_total'      => 100.00,
            'isv_amount'        => 13.04,
            'is_isv_deductible' => true,
            'provider_rtn'      => '08010000000001',
            'provider_invoice_number' => '000-001-01-11111111',
            'provider_invoice_cai'    => 'CCCCCC-CCCCCC-CCCCCC-CCCCCC-CCCCCC-99',
        ]);

        // Deducible sin RTN.
        $this->makeExpense([
            'amount_total'      => 100.00,
            'isv_amount'        => 13.04,
            'is_isv_deductible' => true,
            'provider_rtn'      => null,
            'provider_invoice_number' => '000-001-01-22222222',
            'provider_invoice_cai'    => 'DDDDDD-DDDDDD-DDDDDD-DDDDDD-DDDDDD-99',
        ]);

        // Deducible sin invoice_number.
        $this->makeExpense([
            'amount_total'      => 100.00,
            'isv_amount'        => 13.04,
            'is_isv_deductible' => true,
            'provider_rtn'      => '08010000000003',
            'provider_invoice_number' => null,
            'provider_invoice_cai'    => 'EEEEEE-EEEEEE-EEEEEE-EEEEEE-EEEEEE-99',
        ]);

        $report = $this->service->build(year: 2026, month: 4);

        $this->assertSame(3, $report->summary->deduciblesCount);
        $this->assertSame(2, $report->summary->deduciblesIncompletosCount);
        $this->assertTrue($report->summary->hasIncompleteWarnings());
    }

    // ─── Buckets ──────────────────────────────────────────────

    public function test_buckets_agrupan_por_categoria_metodo_pago_y_sucursal(): void
    {
        // Combustible × 2: 100 + 200 = 300
        $this->makeExpense(['amount_total' => 100.00, 'category' => ExpenseCategory::Combustible->value]);
        $this->makeExpense(['amount_total' => 200.00, 'category' => ExpenseCategory::Combustible->value]);

        // Servicios × 1: 1000
        $this->makeExpense([
            'amount_total'   => 1000.00,
            'category'       => ExpenseCategory::Servicios->value,
            'payment_method' => PaymentMethod::Transferencia->value,
        ]);

        $report = $this->service->build(year: 2026, month: 4);

        // byCategory: ordenado por total desc → servicios (1000) primero, combustible (300) después.
        $catKeys = array_keys($report->summary->byCategory);
        $this->assertSame(ExpenseCategory::Servicios->value, $catKeys[0]);
        $this->assertSame(ExpenseCategory::Combustible->value, $catKeys[1]);
        $this->assertSame(1000.00, $report->summary->byCategory[ExpenseCategory::Servicios->value]['total']);
        $this->assertSame(300.00, $report->summary->byCategory[ExpenseCategory::Combustible->value]['total']);
        $this->assertSame(2, $report->summary->byCategory[ExpenseCategory::Combustible->value]['count']);

        // byPaymentMethod: efectivo (300) y transferencia (1000).
        $this->assertSame(1000.00, $report->summary->byPaymentMethod[PaymentMethod::Transferencia->value]['total']);
        $this->assertSame(300.00, $report->summary->byPaymentMethod[PaymentMethod::Efectivo->value]['total']);

        // byEstablishment: una sola sucursal.
        $this->assertCount(1, $report->summary->byEstablishment);
        $this->assertSame(1300.00, $report->summary->byEstablishment['Matriz Tegucigalpa']['total']);
    }

    // ─── Impacto en caja: solo Efectivo ───────────────────────

    public function test_cashtotal_solo_acumula_gastos_en_efectivo(): void
    {
        // Efectivo × 2: 100 + 50 = 150
        $this->makeExpense(['amount_total' => 100.00, 'payment_method' => PaymentMethod::Efectivo->value]);
        $this->makeExpense(['amount_total' => 50.00, 'payment_method' => PaymentMethod::Efectivo->value]);

        // Tarjeta + transferencia + cheque (NO afectan caja)
        $this->makeExpense(['amount_total' => 200.00, 'payment_method' => PaymentMethod::TarjetaCredito->value]);
        $this->makeExpense(['amount_total' => 300.00, 'payment_method' => PaymentMethod::Transferencia->value]);
        $this->makeExpense(['amount_total' => 400.00, 'payment_method' => PaymentMethod::Cheque->value]);

        $report = $this->service->build(year: 2026, month: 4);

        $this->assertSame(2, $report->summary->cashCount);
        $this->assertSame(150.00, $report->summary->cashTotal);
        $this->assertSame(3, $report->summary->nonCashCount);
        $this->assertSame(900.00, $report->summary->nonCashTotal);
    }
}

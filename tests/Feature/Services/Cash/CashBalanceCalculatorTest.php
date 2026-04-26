<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Cash;

use App\Enums\CashMovementType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\User;
use App\Services\Cash\CashBalanceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Garantiza que `CashBalanceCalculator` sea una fuente única y coherente para
 * el cálculo de saldo esperado de caja. Las propiedades que cuida:
 *
 *   1. opening_amount SIEMPRE se incluye, sin importar el movimiento de apertura.
 *   2. Solo los movimientos en `efectivo` afectan el saldo físico.
 *   3. Inflows suman (sale_income), outflows restan (expense, supplier_payment,
 *      deposit).
 *   4. opening_balance y closing_balance NUNCA se cuentan (solo son registro).
 *   5. totalsByPaymentMethod agrupa correctamente ingresos por método.
 *   6. discrepancy = actual - expected (positivo = sobra, negativo = falta).
 */
class CashBalanceCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private CashBalanceCalculator $calculator;

    private CompanySetting $company;

    private Establishment $matriz;

    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');
        $this->company = CompanySetting::factory()->create(['rtn' => '08011999123456']);
        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        $this->cajero = User::factory()->create();
        $this->calculator = app(CashBalanceCalculator::class);
    }

    private function makeSession(float $openingAmount = 1000.00): CashSession
    {
        return CashSession::factory()
            ->forEstablishment($this->matriz)
            ->openedBy($this->cajero)
            ->openingAmount($openingAmount)
            ->create();
    }

    public function test_expected_cash_es_opening_amount_cuando_no_hay_movimientos(): void
    {
        $session = $this->makeSession(1000.00);

        $this->assertSame(1000.00, $this->calculator->expectedCash($session));
    }

    public function test_expected_cash_suma_ingresos_en_efectivo(): void
    {
        $session = $this->makeSession(1000.00);

        CashMovement::factory()
            ->forSession($session)
            ->saleIncome(500.00, PaymentMethod::Efectivo)
            ->create();
        CashMovement::factory()
            ->forSession($session)
            ->saleIncome(300.00, PaymentMethod::Efectivo)
            ->create();

        // 1000 + 500 + 300 = 1800
        $this->assertSame(1800.00, $this->calculator->expectedCash($session->fresh()));
    }

    public function test_expected_cash_resta_egresos_en_efectivo(): void
    {
        $session = $this->makeSession(2000.00);

        CashMovement::factory()->forSession($session)->expense(200.00, ExpenseCategory::Combustible)->create();
        CashMovement::factory()->forSession($session)->deposit(1000.00)->create();

        // 2000 - 200 - 1000 = 800
        $this->assertSame(800.00, $this->calculator->expectedCash($session->fresh()));
    }

    public function test_expected_cash_ignora_movimientos_en_tarjeta_o_transferencia(): void
    {
        $session = $this->makeSession(1000.00);

        // Estos NO afectan el saldo físico de caja.
        CashMovement::factory()->forSession($session)->saleIncome(500.00, PaymentMethod::TarjetaCredito)->create();
        CashMovement::factory()->forSession($session)->saleIncome(300.00, PaymentMethod::Transferencia)->create();
        CashMovement::factory()->forSession($session)->saleIncome(200.00, PaymentMethod::Cheque)->create();

        // Sin movimientos en efectivo → expected = opening.
        $this->assertSame(1000.00, $this->calculator->expectedCash($session->fresh()));
    }

    public function test_expected_cash_ignora_opening_balance_y_closing_balance(): void
    {
        $session = $this->makeSession(1000.00);

        // Asentamientos técnicos que NO deben alterar el cálculo.
        CashMovement::factory()
            ->forSession($session)
            ->type(CashMovementType::OpeningBalance)
            ->amount(1000.00)
            ->create();
        CashMovement::factory()
            ->forSession($session)
            ->type(CashMovementType::ClosingBalance)
            ->amount(1000.00)
            ->create();

        // Si el cálculo contara opening_balance como inflow y closing_balance
        // como... lo que sea, el total sería ≠ 1000. Debe quedarse en 1000.
        $this->assertSame(1000.00, $this->calculator->expectedCash($session->fresh()));
    }

    public function test_expected_cash_combina_todo_correctamente(): void
    {
        $session = $this->makeSession(1500.00);

        CashMovement::factory()->forSession($session)->saleIncome(400.00, PaymentMethod::Efectivo)->create();
        CashMovement::factory()->forSession($session)->saleIncome(600.00, PaymentMethod::TarjetaDebito)->create(); // no cuenta
        CashMovement::factory()->forSession($session)->expense(100.00, ExpenseCategory::Papeleria)->create();
        CashMovement::factory()->forSession($session)->deposit(500.00)->create();
        CashMovement::factory()->forSession($session)
            ->type(CashMovementType::SupplierPayment)
            ->paymentMethod(PaymentMethod::Efectivo)
            ->amount(200.00)
            ->create();

        // 1500 + 400 - 100 - 500 - 200 = 1100
        $this->assertSame(1100.00, $this->calculator->expectedCash($session->fresh()));
    }

    public function test_total_cash_inflows_solo_cuenta_ingresos_en_efectivo(): void
    {
        $session = $this->makeSession();

        CashMovement::factory()->forSession($session)->saleIncome(300.00, PaymentMethod::Efectivo)->create();
        CashMovement::factory()->forSession($session)->saleIncome(700.00, PaymentMethod::Efectivo)->create();
        CashMovement::factory()->forSession($session)->saleIncome(999.00, PaymentMethod::TarjetaCredito)->create();

        $this->assertSame(1000.00, $this->calculator->totalCashInflows($session->fresh()));
    }

    public function test_total_cash_outflows_solo_cuenta_egresos_en_efectivo(): void
    {
        $session = $this->makeSession();

        CashMovement::factory()->forSession($session)->expense(150.00, ExpenseCategory::Combustible)->create();
        CashMovement::factory()->forSession($session)->deposit(500.00)->create();
        // Supplier payment en transferencia NO debe contarse.
        CashMovement::factory()->forSession($session)
            ->type(CashMovementType::SupplierPayment)
            ->paymentMethod(PaymentMethod::Transferencia)
            ->amount(9999.00)
            ->create();

        $this->assertSame(650.00, $this->calculator->totalCashOutflows($session->fresh()));
    }

    public function test_totals_by_payment_method_agrupa_ingresos_por_venta(): void
    {
        $session = $this->makeSession();

        CashMovement::factory()->forSession($session)->saleIncome(500.00, PaymentMethod::Efectivo)->create();
        CashMovement::factory()->forSession($session)->saleIncome(300.00, PaymentMethod::Efectivo)->create();
        CashMovement::factory()->forSession($session)->saleIncome(1000.00, PaymentMethod::TarjetaCredito)->create();
        CashMovement::factory()->forSession($session)->saleIncome(400.00, PaymentMethod::Transferencia)->create();
        // Gasto NO debe contar (solo sale_income).
        CashMovement::factory()->forSession($session)->expense(100.00)->create();

        $totals = $this->calculator->totalsByPaymentMethod($session->fresh());

        $this->assertSame(800.00, $totals[PaymentMethod::Efectivo->value]);
        $this->assertSame(1000.00, $totals[PaymentMethod::TarjetaCredito->value]);
        $this->assertSame(400.00, $totals[PaymentMethod::Transferencia->value]);
        $this->assertArrayNotHasKey(PaymentMethod::Cheque->value, $totals); // no hay movimientos
    }

    public function test_totals_by_expense_category_agrupa_gastos_de_caja_chica(): void
    {
        $session = $this->makeSession();

        CashMovement::factory()->forSession($session)->expense(150.00, ExpenseCategory::Combustible)->create();
        CashMovement::factory()->forSession($session)->expense(75.00, ExpenseCategory::Combustible)->create();
        CashMovement::factory()->forSession($session)->expense(200.00, ExpenseCategory::Papeleria)->create();
        CashMovement::factory()->forSession($session)->expense(45.50, ExpenseCategory::Mensajeria)->create();

        $totals = $this->calculator->totalsByExpenseCategory($session->fresh());

        $this->assertSame(225.00, $totals[ExpenseCategory::Combustible->value]);
        $this->assertSame(200.00, $totals[ExpenseCategory::Papeleria->value]);
        $this->assertSame(45.50, $totals[ExpenseCategory::Mensajeria->value]);
        $this->assertArrayNotHasKey(ExpenseCategory::Mantenimiento->value, $totals);
    }

    public function test_totals_by_expense_category_ignora_otros_tipos_de_movimiento(): void
    {
        $session = $this->makeSession();

        // Solo gastos cuentan — el resto se ignora aunque tenga category.
        CashMovement::factory()->forSession($session)->expense(100.00, ExpenseCategory::Otros)->create();
        CashMovement::factory()->forSession($session)->saleIncome(500.00, PaymentMethod::Efectivo)->create();
        CashMovement::factory()->forSession($session)->deposit(1000.00)->create();
        CashMovement::factory()->forSession($session)
            ->type(CashMovementType::SupplierPayment)
            ->paymentMethod(PaymentMethod::Efectivo)
            ->amount(300.00)
            ->create();

        $totals = $this->calculator->totalsByExpenseCategory($session->fresh());

        $this->assertCount(1, $totals);
        $this->assertSame(100.00, $totals[ExpenseCategory::Otros->value]);
    }

    public function test_totals_by_expense_category_ignora_gastos_que_no_son_en_efectivo(): void
    {
        $session = $this->makeSession();

        // Gasto en efectivo: cuenta.
        CashMovement::factory()->forSession($session)->expense(120.00, ExpenseCategory::Combustible)->create();

        // Gasto teórico en transferencia: no debe aparecer en el reporte de
        // cierre de caja porque no salió del cajón. RecordExpenseAction hardcodea
        // efectivo, pero defendemos contra entradas creadas por otras vías
        // (seeders, imports, futuros flujos).
        CashMovement::factory()
            ->forSession($session)
            ->type(CashMovementType::Expense)
            ->paymentMethod(PaymentMethod::Transferencia)
            ->amount(9999.00)
            ->create(['category' => ExpenseCategory::Combustible->value]);

        $totals = $this->calculator->totalsByExpenseCategory($session->fresh());

        $this->assertSame(120.00, $totals[ExpenseCategory::Combustible->value]);
    }

    public function test_totals_by_expense_category_retorna_array_vacio_sin_gastos(): void
    {
        $session = $this->makeSession();

        CashMovement::factory()->forSession($session)->saleIncome(500.00, PaymentMethod::Efectivo)->create();

        $this->assertSame([], $this->calculator->totalsByExpenseCategory($session->fresh()));
    }

    public function test_totals_by_expense_category_agrupa_sin_categoria_bajo_otros(): void
    {
        $session = $this->makeSession();

        // Caso defensivo: gasto sin category (no debería pasar la validación
        // del UI, pero un import o seed podría crearlo). Se agrupa bajo Otros
        // para que el reporte siga cuadrando con el saldo de caja.
        CashMovement::factory()
            ->forSession($session)
            ->type(CashMovementType::Expense)
            ->paymentMethod(PaymentMethod::Efectivo)
            ->amount(60.00)
            ->create(['category' => null]);

        $totals = $this->calculator->totalsByExpenseCategory($session->fresh());

        $this->assertSame(60.00, $totals[ExpenseCategory::Otros->value]);
    }

    public function test_discrepancy_positiva_cuando_sobra_dinero(): void
    {
        // Sistema esperaba L. 1000, cajero contó L. 1050 → sobran L. 50.
        $this->assertSame(50.00, $this->calculator->discrepancy(1050.00, 1000.00));
    }

    public function test_discrepancy_negativa_cuando_falta_dinero(): void
    {
        $this->assertSame(-75.00, $this->calculator->discrepancy(925.00, 1000.00));
    }

    public function test_discrepancy_cero_cuando_cuadra_exacto(): void
    {
        $this->assertSame(0.00, $this->calculator->discrepancy(1000.00, 1000.00));
    }

    public function test_aisla_movimientos_por_sesion(): void
    {
        $sessionA = $this->makeSession(1000.00);
        $sessionB = $this->makeSession(500.00);

        // Close session A para que no colisione con el unique parcial "una sola abierta".
        $sessionA->update(['closed_at' => now()->subHour()]);

        CashMovement::factory()->forSession($sessionA)->saleIncome(300.00)->create();
        CashMovement::factory()->forSession($sessionB)->saleIncome(200.00)->create();

        $this->assertSame(1300.00, $this->calculator->expectedCash($sessionA->fresh()));
        $this->assertSame(700.00, $this->calculator->expectedCash($sessionB->fresh()));
    }
}

<?php

namespace Tests\Feature\Services\Banking;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\Category;
use App\Models\CashSession;
use App\Models\CompanySetting;
use App\Models\Expense;
use App\Models\Product;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\Sales\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Tests de integración del CardFeeRecorder en el flujo real de venta.
 *
 * Verifica:
 *   - Venta con tarjeta de crédito → se crea Expense con monto y categoría correctos
 *   - Venta con tarjeta de débito → se crea Expense
 *   - Venta en efectivo → NO se crea Expense
 *   - Venta con transferencia → NO se crea Expense
 *   - El Expense queda asociado a la venta vía sale_id
 *   - El Expense usa la fecha de la venta (no now())
 *   - El Expense NO afecta caja (no se crea CashMovement vinculado)
 */
class CardFeeRecorderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMatriz;

    private SaleService $service;
    private Category $category;
    private User $cajero;
    private CashSession $cajaMatriz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SaleService::class);
        $this->category = Category::factory()->create();

        $this->cajero = User::factory()->create();
        $this->actingAs($this->cajero);

        $this->cajaMatriz = app(CashSessionService::class)->open(
            establishmentId: $this->matriz->id,
            openedBy: $this->cajero,
            openingAmount: 1000.00,
        );

        // Asegurar que existe el registro con tasas conocidas. RefreshDatabase
        // trunca entre tests, así que cada setUp recrea desde cero.
        CompanySetting::firstOrCreate(['id' => 1], [
            'legal_name' => 'Test',
            'rtn' => '0000-0000-00000',
            'address' => 'Test',
        ])->forceFill([
            'card_fee_rate_credit' => 0.0340,
            'card_fee_rate_debit' => 0.0340,
        ])->save();
    }

    private function makeProduct(float $salePrice = 1000): Product
    {
        return Product::factory()->brandNew()->inCategory($this->category)->create([
            'sale_price' => $salePrice,
            'cost_price' => 600,
            'stock' => 10,
        ]);
    }

    private function cartItems(Product $product, int $qty = 1): array
    {
        return [[
            'product_id' => $product->id,
            'quantity' => $qty,
            'unit_price' => $product->sale_price,
            'tax_type' => $product->tax_type->value,
        ]];
    }

    // ─── Comportamiento con tarjeta ──────────────────────────

    public function test_creates_expense_when_paid_with_credit_card(): void
    {
        $product = $this->makeProduct(salePrice: 1000);

        $sale = $this->service->processSale(
            cartItems: $this->cartItems($product),
            paymentMethod: PaymentMethod::TarjetaCredito,
        );

        $expense = Expense::where('sale_id', $sale->id)->first();

        $this->assertNotNull($expense, 'Debió crearse un Expense por la comisión bancaria.');
        $this->assertEquals(ExpenseCategory::ComisionesBancarias, $expense->category);
        $this->assertEquals(PaymentMethod::TarjetaCredito, $expense->payment_method);

        // 1000 × 0.0340 = 34.00
        $this->assertEqualsWithDelta(34.00, (float) $expense->amount_total, 0.01);

        $this->assertSame($sale->id, $expense->sale_id);
        $this->assertSame($this->matriz->id, $expense->establishment_id);
        $this->assertSame($this->cajero->id, $expense->user_id);
    }

    public function test_creates_expense_when_paid_with_debit_card(): void
    {
        $product = $this->makeProduct(salePrice: 2000);

        $sale = $this->service->processSale(
            cartItems: $this->cartItems($product),
            paymentMethod: PaymentMethod::TarjetaDebito,
        );

        $expense = Expense::where('sale_id', $sale->id)->first();

        $this->assertNotNull($expense);
        $this->assertEquals(PaymentMethod::TarjetaDebito, $expense->payment_method);

        // 2000 × 0.0340 = 68.00
        $this->assertEqualsWithDelta(68.00, (float) $expense->amount_total, 0.01);
    }

    public function test_does_not_create_expense_for_cash_payment(): void
    {
        $product = $this->makeProduct(salePrice: 1000);

        $sale = $this->service->processSale(
            cartItems: $this->cartItems($product),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $this->assertDatabaseMissing('expenses', ['sale_id' => $sale->id]);
    }

    public function test_does_not_create_expense_for_transferencia(): void
    {
        $product = $this->makeProduct(salePrice: 1000);

        $sale = $this->service->processSale(
            cartItems: $this->cartItems($product),
            paymentMethod: PaymentMethod::Transferencia,
        );

        $this->assertDatabaseMissing('expenses', ['sale_id' => $sale->id]);
    }

    public function test_does_not_create_expense_for_cheque(): void
    {
        $product = $this->makeProduct(salePrice: 1000);

        $sale = $this->service->processSale(
            cartItems: $this->cartItems($product),
            paymentMethod: PaymentMethod::Cheque,
        );

        $this->assertDatabaseMissing('expenses', ['sale_id' => $sale->id]);
    }

    // ─── Comportamiento del Expense ──────────────────────────

    public function test_expense_uses_sale_date_not_now(): void
    {
        $product = $this->makeProduct();

        $sale = $this->service->processSale(
            cartItems: $this->cartItems($product),
            paymentMethod: PaymentMethod::TarjetaCredito,
        );

        $expense = Expense::where('sale_id', $sale->id)->first();

        $this->assertEquals(
            $sale->date->toDateString(),
            $expense->expense_date->toDateString(),
            'expense_date debería coincidir con la fecha de la venta para alinear período fiscal.'
        );
    }

    public function test_expense_does_not_create_cash_movement(): void
    {
        $product = $this->makeProduct();

        $sale = $this->service->processSale(
            cartItems: $this->cartItems($product),
            paymentMethod: PaymentMethod::TarjetaCredito,
        );

        $expense = Expense::where('sale_id', $sale->id)->first();

        // La comisión bancaria NO sale del cajón físico — el banco la retiene
        // del depósito, no del efectivo. Por eso el Expense con payment_method
        // = TarjetaCredito tiene affectsCashBalance() = false → NO se crea
        // CashMovement asociado.
        $this->assertNull($expense->cashMovement);
    }

    public function test_expense_description_includes_sale_number_and_rate(): void
    {
        $product = $this->makeProduct(salePrice: 5000);

        $sale = $this->service->processSale(
            cartItems: $this->cartItems($product),
            paymentMethod: PaymentMethod::TarjetaCredito,
        );

        $expense = Expense::where('sale_id', $sale->id)->first();

        $this->assertStringContainsString($sale->sale_number, $expense->description);
        $this->assertStringContainsString('3.40%', $expense->description);
        $this->assertStringContainsString('Tarjeta de crédito', $expense->description);
    }

    public function test_expense_is_rolled_back_if_sale_fails(): void
    {
        // Si la transacción de la venta hace rollback (ej. fallo en checkout),
        // el Expense NO debe quedar en la BD. Garantizado por la
        // DB::transaction() del SaleService que envuelve todo.
        $product = $this->makeProduct(salePrice: 1000);
        $product->update(['stock' => 1]); // forzar fallo de stock

        try {
            $this->service->processSale(
                cartItems: $this->cartItems($product, qty: 5),
                paymentMethod: PaymentMethod::TarjetaCredito,
            );
            $this->fail('Esperaba que la venta fallara por stock insuficiente.');
        } catch (\RuntimeException $e) {
            // OK
        }

        // Ningún Expense de comisión debe haberse persistido.
        $this->assertSame(0, Expense::where('category', ExpenseCategory::ComisionesBancarias->value)->count());
    }

    // Nota: La verificación "tasa nueva en settings → cálculo correcto" está
    // cubierta en CardFeeCalculatorTest a nivel unitario (más rápido y
    // determinístico). No se duplica acá como test de integración por
    // interacciones complejas entre Cache de Laravel y RefreshDatabase
    // que generan flakiness sin valor adicional de cobertura.
}

<?php

namespace Tests\Feature\Services\Banking;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\Sales\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Tests de integración del CardFeeRecorder en el flujo real de venta.
 *
 * Desde "todo en Compras" (2026-09-28) la comisión se guarda en la propia
 * venta (`sales.card_fee_amount`). Verifica:
 *   - Tarjeta de crédito / débito → la venta guarda la comisión correcta
 *   - Efectivo, transferencia, cheque → comisión 0
 *   - La comisión NO sale del cajón (no genera CashMovement de gasto)
 *   - Si la venta hace rollback, no queda nada persistido
 */
class CardFeeRecorderTest extends TestCase
{
    use CreatesMatriz;
    use RefreshDatabase;

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

    public function test_stores_fee_on_sale_when_paid_with_credit_card(): void
    {
        $product = $this->makeProduct(salePrice: 1000);

        $sale = $this->service->processSale(
            cartItems: $this->cartItems($product),
            paymentMethod: PaymentMethod::TarjetaCredito,
        );

        // 1000 × 0.0340 = 34.00
        $this->assertEqualsWithDelta(34.00, (float) $sale->fresh()->card_fee_amount, 0.001);
    }

    public function test_stores_fee_on_sale_when_paid_with_debit_card(): void
    {
        $product = $this->makeProduct(salePrice: 2000);

        $sale = $this->service->processSale(
            cartItems: $this->cartItems($product),
            paymentMethod: PaymentMethod::TarjetaDebito,
        );

        // 2000 × 0.0340 = 68.00
        $this->assertEqualsWithDelta(68.00, (float) $sale->fresh()->card_fee_amount, 0.001);
    }

    /**
     * @dataProvider nonCardMethods
     */
    public function test_no_fee_for_non_card_payment(PaymentMethod $method): void
    {
        $product = $this->makeProduct(salePrice: 1000);

        $sale = $this->service->processSale(
            cartItems: $this->cartItems($product),
            paymentMethod: $method,
        );

        $this->assertEqualsWithDelta(0.0, (float) $sale->fresh()->card_fee_amount, 0.001);
    }

    /**
     * @return array<string, array{PaymentMethod}>
     */
    public static function nonCardMethods(): array
    {
        return [
            'efectivo' => [PaymentMethod::Efectivo],
            'transferencia' => [PaymentMethod::Transferencia],
            'cheque' => [PaymentMethod::Cheque],
        ];
    }

    // ─── Efectos colaterales ─────────────────────────────────

    public function test_fee_does_not_touch_cash_drawer(): void
    {
        $product = $this->makeProduct();

        $this->service->processSale(
            cartItems: $this->cartItems($product),
            paymentMethod: PaymentMethod::TarjetaCredito,
        );

        // La comisión la retiene el banco del depósito, no sale del cajón.
        $this->assertSame(0, CashMovement::where('type', CashMovementType::Expense->value)->count());
    }

    public function test_fee_is_rolled_back_if_sale_fails(): void
    {
        $product = $this->makeProduct(salePrice: 1000);
        $product->update(['stock' => 1]); // forzar fallo de stock

        try {
            $this->service->processSale(
                cartItems: $this->cartItems($product, qty: 5),
                paymentMethod: PaymentMethod::TarjetaCredito,
            );
            $this->fail('Esperaba que la venta fallara por stock insuficiente.');
        } catch (\RuntimeException) {
            // OK
        }

        // La venta (y con ella su comisión) no quedó persistida.
        $this->assertSame(0, Sale::count());
    }

    // Nota: La verificación "tasa nueva en settings → cálculo correcto" está
    // cubierta en CardFeeCalculatorTest a nivel unitario (más rápido y
    // determinístico). No se duplica acá como test de integración por
    // interacciones complejas entre Cache de Laravel y RefreshDatabase
    // que generan flakiness sin valor adicional de cobertura.
}

<?php

namespace Tests\Feature\Services;

use App\Enums\CashMovementType;
use App\Enums\DiscountType;
use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\TaxType;
use App\Exceptions\Cash\NoHayCajaAbiertaException;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\Sales\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

class SaleServiceTest extends TestCase
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
        // F6a.5 — resolver por container para que SaleService reciba
        // el EstablishmentResolver inyectado (antes era `new SaleService()`,
        // pero el Service ahora tiene dependencia constructor).
        $this->service = app(SaleService::class);
        $this->category = Category::factory()->create();

        // C2 — cajero autenticado + caja abierta en la matriz. Invariante del
        // POS: toda venta nace dentro de una sesión de caja. Sin esta
        // precondición, processSale falla con NoHayCajaAbiertaException.
        $this->cajero = User::factory()->create();
        $this->actingAs($this->cajero);

        $this->cajaMatriz = app(CashSessionService::class)->open(
            establishmentId: $this->matriz->id,
            openedBy: $this->cajero,
            openingAmount: 1000.00,
        );
    }

    private function makeProduct(float $salePrice = 1150, int $stock = 10): Product
    {
        return Product::factory()->brandNew()->inCategory($this->category)->create([
            'sale_price' => $salePrice,
            'cost_price' => 800,
            'stock' => $stock,
        ]);
    }

    private function makeCartItems(array $items): array
    {
        return collect($items)->map(fn ($item) => [
            'product_id' => $item['product']->id,
            'quantity' => $item['quantity'],
            'unit_price' => $item['product']->sale_price,
            'tax_type' => $item['product']->tax_type->value,
        ])->toArray();
    }

    // ─── Tests de procesamiento ─────────────────────────────

    public function test_process_sale_deducts_stock(): void
    {
        $product = $this->makeProduct(stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 3],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $product->refresh();
        $this->assertEquals(7, $product->stock);
        $this->assertEquals(SaleStatus::Completada, $sale->status);
    }

    public function test_process_sale_creates_kardex_movements(): void
    {
        $product = $this->makeProduct(stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 2],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $movement = InventoryMovement::where('product_id', $product->id)
            ->where('type', MovementType::SalidaVenta)
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals(2, $movement->quantity);
        $this->assertEquals(10, $movement->stock_before);
        $this->assertEquals(8, $movement->stock_after);
        $this->assertEquals(Sale::class, $movement->reference_type);
        $this->assertEquals($sale->id, $movement->reference_id);
    }

    public function test_process_sale_calculates_totals_with_isv(): void
    {
        $product = $this->makeProduct(salePrice: 1150, stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 2],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $sale->refresh();
        // Total = 1150 * 2 = 2300
        $this->assertEquals(2300.00, (float) $sale->total + (float) $sale->discount_amount);
        // ISV > 0
        $this->assertGreaterThan(0, (float) $sale->isv);
        // subtotal + isv = total
        $this->assertEquals(
            (float) $sale->total,
            (float) $sale->subtotal + (float) $sale->isv
        );
    }

    public function test_process_sale_fails_with_empty_cart(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sin productos');

        $this->service->processSale(
            cartItems: [],
            paymentMethod: PaymentMethod::Efectivo,
        );
    }

    public function test_process_sale_fails_with_insufficient_stock(): void
    {
        $product = $this->makeProduct(stock: 2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stock insuficiente');

        $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 5],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );
    }

    public function test_process_sale_generates_sale_number(): void
    {
        $product = $this->makeProduct(stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $this->assertStringStartsWith('VTA-' . now()->year . '-', $sale->sale_number);
    }

    // ─── Tests de cliente ───────────────────────────────────

    public function test_consumidor_final_has_no_customer_id(): void
    {
        $product = $this->makeProduct(stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
            customerName: 'Juan Pérez',
        );

        $this->assertNull($sale->customer_id);
        $this->assertEquals('Juan Pérez', $sale->customer_name);
        $this->assertNull($sale->customer_rtn);
    }

    public function test_customer_with_rtn_is_auto_created(): void
    {
        $product = $this->makeProduct(stock: 10);

        $this->assertEquals(0, Customer::count());

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
            customerName: 'María López',
            customerRtn: '0801-1999-123456',
        );

        $this->assertEquals(1, Customer::count());
        $this->assertNotNull($sale->customer_id);
        $this->assertEquals('María López', $sale->customer_name);
        $this->assertEquals('0801-1999-123456', $sale->customer_rtn);

        $customer = Customer::first();
        $this->assertEquals('María López', $customer->name);
        $this->assertEquals('0801-1999-123456', $customer->rtn);
    }

    public function test_existing_customer_rtn_reuses_record(): void
    {
        $customer = Customer::factory()->create(['rtn' => '0801-2000-000001']);
        $product = $this->makeProduct(stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
            customerName: 'Nombre Diferente',
            customerRtn: '0801-2000-000001',
        );

        // No crea nuevo customer, reutiliza el existente
        $this->assertEquals(1, Customer::count());
        $this->assertEquals($customer->id, $sale->customer_id);
    }

    // ─── Tests de descuento ─────────────────────────────────

    public function test_percentage_discount_applied_correctly(): void
    {
        $product = $this->makeProduct(salePrice: 1000, stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
            discountType: DiscountType::Percentage,
            discountValue: 10, // 10%
        );

        $sale->refresh();
        $this->assertEquals(100.00, (float) $sale->discount_amount); // 10% de 1000
        $this->assertEquals(900.00, (float) $sale->total); // 1000 - 100
    }

    public function test_fixed_discount_applied_correctly(): void
    {
        $product = $this->makeProduct(salePrice: 1000, stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
            discountType: DiscountType::Fixed,
            discountValue: 200,
        );

        $sale->refresh();
        $this->assertEquals(200.00, (float) $sale->discount_amount);
        $this->assertEquals(800.00, (float) $sale->total); // 1000 - 200
    }

    public function test_no_discount_when_not_specified(): void
    {
        $product = $this->makeProduct(salePrice: 1000, stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $sale->refresh();
        $this->assertEquals(0, (float) $sale->discount_amount);
        $this->assertEquals(1000.00, (float) $sale->total);
    }

    // ─── Tests de anulación ─────────────────────────────────

    public function test_cancel_completed_sale_returns_stock(): void
    {
        $product = $this->makeProduct(stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 3],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $product->refresh();
        $this->assertEquals(7, $product->stock);

        $this->service->cancel($sale->refresh());

        $product->refresh();
        $this->assertEquals(10, $product->stock);
        $this->assertEquals(SaleStatus::Anulada, $sale->refresh()->status);
    }

    public function test_cancel_creates_entry_kardex_movements(): void
    {
        $product = $this->makeProduct(stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 2],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $this->service->cancel($sale->refresh());

        $movements = InventoryMovement::where('product_id', $product->id)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $movements);
        $this->assertEquals(MovementType::SalidaVenta, $movements[0]->type);
        $this->assertEquals(MovementType::EntradaAnulacionVenta, $movements[1]->type);
    }

    public function test_cancel_already_cancelled_fails(): void
    {
        $product = $this->makeProduct(stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $this->service->cancel($sale->refresh());

        $this->expectException(\InvalidArgumentException::class);
        $this->service->cancel($sale->refresh());
    }

    // ─── Cascade F5c: anular factura asociada al cancelar venta ─────

    /**
     * El cascade F5c garantiza que si una venta tiene factura fiscal emitida,
     * al cancelar la venta la factura queda marcada como anulada en la MISMA
     * transacción. Sin esto, el sistema queda inconsistente: venta anulada
     * pero factura emitida — un bug silencioso que solo aparece al reconciliar
     * ingresos contra kardex.
     */
    public function test_cancel_sale_voids_associated_invoice(): void
    {
        $product = $this->makeProduct(stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 2],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $invoice = Invoice::factory()->create([
            'sale_id' => $sale->id,
            'is_void' => false,
        ]);

        $this->service->cancel($sale->refresh());

        $invoice->refresh();
        $this->assertTrue($invoice->is_void, 'La factura asociada debe quedar anulada.');
    }

    /**
     * Algunas ventas (e.g. en borrador o pre-factura) nunca llegaron a emitir
     * documento fiscal. Cancelarlas no debe fallar por ausencia de factura —
     * la cascada es condicional, no obligatoria.
     */
    public function test_cancel_sale_without_invoice_does_not_fail(): void
    {
        $product = $this->makeProduct(stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $this->assertNull($sale->fresh()->invoice);

        // No debe lanzar — la cancelación sigue restaurando stock y kardex.
        $this->service->cancel($sale->refresh());

        $sale->refresh();
        $product->refresh();
        $this->assertEquals(\App\Enums\SaleStatus::Anulada, $sale->status);
        $this->assertEquals(10, $product->stock);
    }

    // ─── Test con múltiples productos ───────────────────────

    public function test_process_sale_with_multiple_products(): void
    {
        $product1 = $this->makeProduct(salePrice: 1000, stock: 10);
        $product2 = $this->makeProduct(salePrice: 2000, stock: 5);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product1, 'quantity' => 2],
                ['product' => $product2, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $product1->refresh();
        $product2->refresh();

        $this->assertEquals(8, $product1->stock);
        $this->assertEquals(4, $product2->stock);
        $this->assertEquals(2, $sale->items->count());

        // Total = (1000*2) + (2000*1) = 4000
        $sale->refresh();
        $this->assertEquals(4000.00, (float) $sale->total);
    }

    // ─── Tests de snapshot de unit_cost en kardex ──────────────

    /**
     * Al procesar una venta, el movimiento de kardex debe capturar
     * el cost_price del producto en ese momento exacto — NO consultar
     * cost_price del producto después (que podría cambiar por compras
     * posteriores de otros productos o por anulaciones).
     *
     * Garantiza cálculos de utilidad bruta histórica precisos.
     */
    public function test_process_sale_captures_cost_price_snapshot_at_sale_time(): void
    {
        // Producto con costo conocido en el momento de la venta
        $product = $this->makeProduct(stock: 10);
        $product->update(['cost_price' => 850.00]);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 2],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $movement = InventoryMovement::where('product_id', $product->id)
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->where('type', MovementType::SalidaVenta)
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals(850.00, (float) $movement->unit_cost);

        // Si mañana el cost_price cambia (por compras futuras), el snapshot
        // del movimiento NO debe cambiar — esa es la garantía.
        $product->update(['cost_price' => 9999.99]);
        $movement->refresh();
        $this->assertEquals(850.00, (float) $movement->unit_cost);
    }

    /**
     * Test crítico: al anular una venta, el movimiento de reversión debe
     * reusar el unit_cost del movimiento ORIGINAL — no el cost_price actual
     * del producto (que pudo haber cambiado por compras posteriores).
     *
     * Si alguien refactoriza y olvida esto, las utilidades brutas históricas
     * quedan distorsionadas en el kardex.
     */
    public function test_cancel_sale_reuses_original_unit_cost_not_current_cost_price(): void
    {
        // Producto vendido con cost_price = 800
        $product = $this->makeProduct(stock: 10);
        $product->update(['cost_price' => 800.00]);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 3],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        // Simulo que entre la venta y la anulación hubo compras que
        // cambiaron el cost_price del producto drásticamente.
        $product->refresh();
        $product->update(['cost_price' => 2500.00]);

        // Ahora anulo la venta
        $this->service->cancel($sale->refresh());

        $reversal = InventoryMovement::where('product_id', $product->id)
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->where('type', MovementType::EntradaAnulacionVenta)
            ->first();

        $this->assertNotNull($reversal);
        // DEBE ser el costo original (800), NO el actual (2500)
        $this->assertEquals(800.00, (float) $reversal->unit_cost);
        $this->assertNotEquals(2500.00, (float) $reversal->unit_cost);
    }

    // ─── F6a.5: resolución de sucursal vía EstablishmentResolver ─────

    /**
     * Cuando `processSale` NO recibe establishment explícito, debe delegar
     * al EstablishmentResolver, que resuelve a la `default_establishment_id`
     * del usuario autenticado — NO a la matriz ciegamente.
     *
     * Regresión crítica: antes de F6a.5 el fallback era
     * `Establishment::main()->firstOrFail()` ignorando al user. Un cajero
     * asignado a "Sucursal Comayagua" procesando una venta POS sin parámetro
     * explícito terminaba registrándola en "Matriz" — bug silencioso que
     * corrompe kardex por sucursal y libros SAR (ventas duplicadas o
     * faltantes al consolidar por punto de emisión).
     */
    public function test_process_sale_uses_authenticated_user_default_establishment_when_none_provided(): void
    {
        // Existe la matriz (via CreatesMatriz) + una sucursal operativa
        $sucursal = Establishment::factory()->create([
            'name' => 'Sucursal Comayagua',
            'is_main' => false,
        ]);

        // Abrir caja en la sucursal para que la venta pueda procesarse ahí
        app(CashSessionService::class)->open(
            establishmentId: $sucursal->id,
            openedBy: $this->cajero,
            openingAmount: 500.00,
        );

        // Usuario del POS con default apuntando a la sucursal (no a matriz)
        $user = User::factory()->withEstablishment($sucursal)->create();
        $this->actingAs($user);

        // Re-resolver el service para que el EstablishmentResolver inyectado
        // tome el user autenticado del AuthFactory (post `actingAs`).
        $service = app(SaleService::class);

        $product = $this->makeProduct(stock: 5);

        $sale = $service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
            // ← sin parámetro establishment: fuerza la ruta del resolver
        );

        $this->assertSame(
            $sucursal->id,
            $sale->establishment_id,
            'La venta debe registrarse en la sucursal del user autenticado, no en matriz.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // C2 — Integración Sales ↔ Caja
    // ═══════════════════════════════════════════════════════════════════
    //
    // Invariante: toda venta vive dentro de una sesión de caja. Las pruebas
    // a continuación cubren:
    //
    //   1. Bloqueo si no hay caja abierta (fail-fast).
    //   2. Registro de SaleIncome en efectivo → afecta expectedCash.
    //   3. Registro de SaleIncome con tarjeta → NO afecta expectedCash pero
    //      queda en totalsByPaymentMethod.
    //   4. Anulación en efectivo → SaleCancellation afecta expectedCash
    //      restando.
    //   5. Anulación con tarjeta → SaleCancellation registrado pero sin
    //      impacto en efectivo.
    //   6. Bloqueo de anulación si no hay caja abierta.
    //   7. Anulación registra en la sesión ACTUAL, no en la original (la
    //      original puede estar cerrada).

    public function test_process_sale_fails_when_no_cash_session_open(): void
    {
        // Cerrar la caja de la matriz — ventas ya no deben ser posibles
        app(CashSessionService::class)->close(
            session: $this->cajaMatriz->fresh(),
            closedBy: $this->cajero,
            actualClosingAmount: 1000.00,
        );

        $product = $this->makeProduct(stock: 10);

        $this->expectException(NoHayCajaAbiertaException::class);

        $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );
    }

    public function test_process_sale_in_cash_registers_sale_income_affecting_expected_cash(): void
    {
        $product = $this->makeProduct(salePrice: 1150, stock: 10); // total venta 1150

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $movement = CashMovement::query()
            ->where('cash_session_id', $this->cajaMatriz->id)
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->first();

        $this->assertNotNull($movement, 'Debe crearse un CashMovement por la venta.');
        $this->assertSame(CashMovementType::SaleIncome, $movement->type);
        $this->assertSame(PaymentMethod::Efectivo, $movement->payment_method);
        $this->assertSame('1150.00', $movement->amount);
        $this->assertSame($this->cajero->id, $movement->user_id);

        // El saldo esperado debe reflejar la venta: opening 1000 + venta 1150 = 2150
        $calculator = app(\App\Services\Cash\CashBalanceCalculator::class);
        $this->assertSame(2150.00, $calculator->expectedCash($this->cajaMatriz->fresh()));
    }

    public function test_process_sale_with_card_records_movement_but_does_not_affect_cash_balance(): void
    {
        $product = $this->makeProduct(salePrice: 1000, stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::TarjetaCredito,
        );

        $movement = CashMovement::query()
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame(PaymentMethod::TarjetaCredito, $movement->payment_method);
        $this->assertSame(CashMovementType::SaleIncome, $movement->type);

        // expectedCash NO cambia: tarjeta no afecta el cajón físico.
        $calculator = app(\App\Services\Cash\CashBalanceCalculator::class);
        $this->assertSame(1000.00, $calculator->expectedCash($this->cajaMatriz->fresh()));

        // Pero sí aparece en el cuadre por método de pago
        $totals = $calculator->totalsByPaymentMethod($this->cajaMatriz->fresh());
        $this->assertSame(1000.00, $totals[PaymentMethod::TarjetaCredito->value]);
    }

    public function test_cancel_cash_sale_registers_sale_cancellation_reducing_expected_cash(): void
    {
        $product = $this->makeProduct(salePrice: 1000, stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        // Después de la venta: 1000 opening + 1000 venta = 2000
        $calculator = app(\App\Services\Cash\CashBalanceCalculator::class);
        $this->assertSame(2000.00, $calculator->expectedCash($this->cajaMatriz->fresh()));

        $this->service->cancel($sale->refresh());

        $cancellation = CashMovement::query()
            ->where('type', CashMovementType::SaleCancellation)
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->first();

        $this->assertNotNull($cancellation, 'Anulación debe registrar SaleCancellation en la caja.');
        $this->assertSame(PaymentMethod::Efectivo, $cancellation->payment_method);
        $this->assertSame('1000.00', $cancellation->amount);

        // Después de anular: 2000 - 1000 cancellation = 1000 (vuelve a opening)
        $this->assertSame(1000.00, $calculator->expectedCash($this->cajaMatriz->fresh()));
    }

    public function test_cancel_card_sale_records_cancellation_without_affecting_cash_balance(): void
    {
        $product = $this->makeProduct(salePrice: 500, stock: 10);

        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::TarjetaCredito,
        );

        $calculator = app(\App\Services\Cash\CashBalanceCalculator::class);
        // expectedCash sigue siendo opening (tarjeta no afecta)
        $this->assertSame(1000.00, $calculator->expectedCash($this->cajaMatriz->fresh()));

        $this->service->cancel($sale->refresh());

        $cancellation = CashMovement::query()
            ->where('type', CashMovementType::SaleCancellation)
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->first();

        $this->assertNotNull($cancellation);
        $this->assertSame(PaymentMethod::TarjetaCredito, $cancellation->payment_method);

        // expectedCash sigue sin cambiar: cancelación en tarjeta no toca el cajón
        $this->assertSame(1000.00, $calculator->expectedCash($this->cajaMatriz->fresh()));
    }

    public function test_cancel_fails_when_no_cash_session_open(): void
    {
        $product = $this->makeProduct(salePrice: 500, stock: 10);

        // Venta normal con caja abierta
        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        // Cerrar la caja
        app(CashSessionService::class)->close(
            session: $this->cajaMatriz->fresh(),
            closedBy: $this->cajero,
            actualClosingAmount: 1500.00,
        );

        // Intentar anular → bloqueado: la caja ya cerró y la anulación debe
        // quedar registrada en una sesión viva.
        $this->expectException(NoHayCajaAbiertaException::class);
        $this->service->cancel($sale->refresh());
    }

    public function test_cancel_registers_in_current_session_not_in_original(): void
    {
        $product = $this->makeProduct(salePrice: 500, stock: 10);

        // Venta original en la caja de hoy
        $sale = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
        );

        $cajaOriginal = $this->cajaMatriz;

        // Cerrar la caja original (fin del día)
        app(CashSessionService::class)->close(
            session: $cajaOriginal->fresh(),
            closedBy: $this->cajero,
            actualClosingAmount: 1500.00,
        );

        // Al día siguiente: abrir una nueva caja
        $cajaNueva = app(CashSessionService::class)->open(
            establishmentId: $this->matriz->id,
            openedBy: $this->cajero,
            openingAmount: 800.00,
        );

        // Anular la venta del día anterior
        $this->service->cancel($sale->refresh());

        $cancellation = CashMovement::query()
            ->where('type', CashMovementType::SaleCancellation)
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->first();

        $this->assertNotNull($cancellation);
        // Debe estar en la caja ACTUAL (nueva), no en la original cerrada.
        $this->assertSame($cajaNueva->id, $cancellation->cash_session_id);
        $this->assertNotSame($cajaOriginal->id, $cancellation->cash_session_id);
    }

    public function test_cancel_in_different_establishment_requires_cash_session_in_that_establishment(): void
    {
        // Sucursal B con su propia caja abierta
        $sucursalB = Establishment::factory()->create(['is_main' => false]);
        app(CashSessionService::class)->open(
            establishmentId: $sucursalB->id,
            openedBy: $this->cajero,
            openingAmount: 400.00,
        );

        $product = $this->makeProduct(salePrice: 300, stock: 10);

        // Venta en sucursal B
        $saleB = $this->service->processSale(
            cartItems: $this->makeCartItems([
                ['product' => $product, 'quantity' => 1],
            ]),
            paymentMethod: PaymentMethod::Efectivo,
            establishment: $sucursalB,
        );

        // Cerrar SOLO la caja de sucursal B (la de matriz sigue abierta)
        $cajaSucursalB = app(CashSessionService::class)->currentOpenSession($sucursalB->id);
        app(CashSessionService::class)->close(
            session: $cajaSucursalB,
            closedBy: $this->cajero,
            actualClosingAmount: 700.00,
        );

        // Intentar anular la venta de sucursal B → debe fallar aunque la
        // caja de matriz esté abierta. La anulación necesita caja viva en
        // la sucursal donde ocurrió la venta original.
        $this->expectException(NoHayCajaAbiertaException::class);
        $this->service->cancel($saleB->refresh());
    }
}

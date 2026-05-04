<?php

namespace Tests\Feature\Services\Banking;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\RepairItemSource;
use App\Enums\TaxType;
use App\Models\CaiRange;
use App\Models\CashSession;
use App\Models\CompanySetting;
use App\Models\DeviceCategory;
use App\Models\Expense;
use App\Models\Repair;
use App\Models\RepairItem;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\Repairs\RepairDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Tests específicos: comisión bancaria en entrega de reparaciones.
 *
 * Verifica el comportamiento crítico:
 *   - Comisión calculada sobre el SALDO (outstanding), NO sobre el total
 *     de la venta. Si hubo anticipo, el anticipo se cobró aparte (siempre
 *     en efectivo) y no debe contaminar el cálculo de la comisión.
 *   - Si saldo = 0 (anticipo cubrió todo), no se crea Expense.
 *   - Si la entrega se cobra en efectivo, no hay comisión aunque el total
 *     sea grande.
 */
class RepairDeliveryCardFeeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMatriz;

    private RepairDeliveryService $service;
    private User $cajero;
    private CashSession $caja;
    private DeviceCategory $deviceCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RepairDeliveryService::class);
        $this->cajero = User::factory()->create();
        $this->actingAs($this->cajero);

        $this->caja = app(CashSessionService::class)->open(
            establishmentId: $this->matriz->id,
            openedBy: $this->cajero,
            openingAmount: 1000.00,
        );

        $this->deviceCategory = DeviceCategory::factory()->create();

        // CAI activo para que InvoiceService::generateFromSale pueda emitir.
        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => null,
        ]);

        // Asegurar fila con tasas conocidas (RefreshDatabase trunca entre tests).
        CompanySetting::firstOrCreate(['id' => 1], [
            'legal_name' => 'Test',
            'rtn' => '0000-0000-00000',
            'address' => 'Test',
        ])->forceFill([
            'card_fee_rate_credit' => 0.0340,
            'card_fee_rate_debit' => 0.0340,
        ])->save();
    }

    /**
     * Helper: crear repair en estado ListoEntrega con un item de honorarios
     * de monto fijo y opcionalmente con anticipo cobrado.
     */
    private function makeRepair(float $totalServicio, float $advance = 0.0): Repair
    {
        $repair = Repair::factory()
            ->readyForDelivery()
            ->state([
                'establishment_id' => $this->matriz->id,
                'device_category_id' => $this->deviceCategory->id,
                'advance_payment' => $advance,
            ])
            ->create();

        // Honorarios EXENTOS (típico en reparaciones electrónicas en HN).
        RepairItem::create([
            'repair_id' => $repair->id,
            'source' => RepairItemSource::HonorariosReparacion,
            'product_id' => null,
            'description' => 'Mano de obra reparación',
            'quantity' => 1,
            'unit_price' => $totalServicio,
            'tax_type' => TaxType::Exento,
            'subtotal' => $totalServicio,
            'isv_amount' => 0,
            'total' => $totalServicio,
        ]);

        $repair->update([
            'subtotal' => $totalServicio,
            'exempt_total' => $totalServicio,
            'taxable_total' => 0,
            'isv' => 0,
            'total' => $totalServicio,
        ]);

        return $repair->fresh('items');
    }

    public function test_creates_expense_when_balance_paid_with_credit_card_no_advance(): void
    {
        // Total 2000, sin anticipo, todo se cobra al entregar con tarjeta.
        $repair = $this->makeRepair(totalServicio: 2000.00);

        $delivered = $this->service->deliver(
            repair: $repair,
            paymentMethod: PaymentMethod::TarjetaCredito,
        );

        $expense = Expense::where('sale_id', $delivered->sale_id)->first();

        $this->assertNotNull($expense);
        $this->assertEquals(ExpenseCategory::ComisionesBancarias, $expense->category);
        // Comisión sobre el TOTAL (no hubo anticipo): 2000 × 3.4% = 68.00
        $this->assertEqualsWithDelta(68.00, (float) $expense->amount_total, 0.01);
    }

    public function test_calculates_fee_only_on_outstanding_when_advance_was_collected(): void
    {
        // Total 1000. Anticipo de 500 (siempre cobrado en efectivo, no genera
        // comisión). Saldo de 500 cobrado al entregar con tarjeta crédito.
        // La comisión debe ser sobre 500, no sobre 1000.
        $repair = $this->makeRepair(totalServicio: 1000.00, advance: 500.00);

        $delivered = $this->service->deliver(
            repair: $repair,
            paymentMethod: PaymentMethod::TarjetaCredito,
        );

        $expense = Expense::where('sale_id', $delivered->sale_id)->first();

        $this->assertNotNull($expense);
        // 500 × 3.4% = 17.00 (NO 1000 × 3.4% = 34.00)
        $this->assertEqualsWithDelta(17.00, (float) $expense->amount_total, 0.01);
    }

    public function test_does_not_create_expense_when_outstanding_is_zero(): void
    {
        // Total 1000. Anticipo cubre todo (1000). No hay saldo a cobrar al
        // entregar — no pasó tarjeta por el POS bancario, no hay comisión.
        $repair = $this->makeRepair(totalServicio: 1000.00, advance: 1000.00);

        $delivered = $this->service->deliver(
            repair: $repair,
            paymentMethod: PaymentMethod::TarjetaCredito, // método "default" pero saldo=0
        );

        $this->assertDatabaseMissing('expenses', [
            'sale_id' => $delivered->sale_id,
            'category' => ExpenseCategory::ComisionesBancarias->value,
        ]);
    }

    public function test_does_not_create_expense_when_balance_paid_with_cash(): void
    {
        $repair = $this->makeRepair(totalServicio: 2000.00);

        $delivered = $this->service->deliver(
            repair: $repair,
            paymentMethod: PaymentMethod::Efectivo,
        );

        $this->assertDatabaseMissing('expenses', [
            'sale_id' => $delivered->sale_id,
            'category' => ExpenseCategory::ComisionesBancarias->value,
        ]);
    }
}

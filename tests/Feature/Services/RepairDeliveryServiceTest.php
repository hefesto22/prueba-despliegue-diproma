<?php

namespace Tests\Feature\Services;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Enums\RepairItemCondition;
use App\Enums\RepairItemSource;
use App\Enums\RepairStatus;
use App\Enums\TaxType;
use App\Exceptions\Cash\NoHayCajaAbiertaException;
use App\Exceptions\Repairs\InsufficientStockOnDeliveryException;
use App\Exceptions\Repairs\RepairDeliveryException;
use App\Exceptions\Repairs\RepairTransitionException;
use App\Models\CaiRange;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\DeviceCategory;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Repair;
use App\Models\RepairItem;
use App\Models\Sale;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\Repairs\RepairDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Tests críticos del flujo de entrega de Reparación.
 *
 * La entrega es la transición más sensible del módulo: combina facturación
 * CAI, descuento de inventario, ingreso a caja y borrado de fotos. Estos
 * tests verifican que la transacción atómica respeta los invariantes
 * fiscales y operativos del proyecto.
 */
class RepairDeliveryServiceTest extends TestCase
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

        // CAI activo para que InvoiceService::generateFromSale pueda emitir
        // factura. Sin esto los tests de happy path fallan con
        // NoHayCaiActivoException — comportamiento correcto del Resolver,
        // pero ortogonal a lo que valida este test.
        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => null, // modo centralizado
        ]);
    }

    /**
     * Construye una Reparación en estado ListoEntrega con los items dados.
     *
     * @param  array<int, array{source: RepairItemSource, condition?: RepairItemCondition, product_id?: ?int, description?: string, unit_price: float, quantity?: float, tax_type: TaxType}>  $items
     */
    private function makeRepairListoEntrega(array $items, float $advance = 0.0): Repair
    {
        $repair = Repair::factory()
            ->readyForDelivery()
            ->state([
                'establishment_id' => $this->matriz->id,
                'device_category_id' => $this->deviceCategory->id,
                'advance_payment' => $advance,
            ])
            ->create();

        $multiplier = (float) config('tax.multiplier', 1.15);
        $subtotal = 0;
        $exempt = 0;
        $taxable = 0;
        $isvTotal = 0;
        $totalGross = 0;

        foreach ($items as $item) {
            $unitPrice = (float) $item['unit_price'];
            $quantity = (float) ($item['quantity'] ?? 1);
            $lineTotal = round($unitPrice * $quantity, 2);

            if ($item['tax_type'] === TaxType::Gravado15) {
                $base = round($lineTotal / $multiplier, 2);
                $isv = round($lineTotal - $base, 2);
                $taxable += $base;
            } else {
                $base = $lineTotal;
                $isv = 0;
                $exempt += $base;
            }

            RepairItem::create([
                'repair_id' => $repair->id,
                'source' => $item['source'],
                'product_id' => $item['product_id'] ?? null,
                'condition' => $item['condition'] ?? null,
                'description' => $item['description'] ?? 'Item test',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_type' => $item['tax_type'],
                'subtotal' => $base,
                'isv_amount' => $isv,
                'total' => $lineTotal,
            ]);

            $subtotal += $base;
            $isvTotal += $isv;
            $totalGross += $lineTotal;
        }

        $repair->update([
            'subtotal' => $subtotal,
            'exempt_total' => $exempt,
            'taxable_total' => $taxable,
            'isv' => $isvTotal,
            'total' => $totalGross,
        ]);

        return $repair->fresh('items');
    }

    private function makeProduct(int $stock = 10, float $salePrice = 1150): Product
    {
        return Product::factory()->brandNew()->inCategory(
            Category::factory()->create()
        )->create([
            'sale_price' => $salePrice,
            'cost_price' => 800,
            'stock' => $stock,
        ]);
    }

    public function test_happy_path_genera_sale_invoice_descuenta_stock_e_ingresa_caja(): void
    {
        $product = $this->makeProduct(stock: 5);

        $repair = $this->makeRepairListoEntrega([
            ['source' => RepairItemSource::HonorariosReparacion, 'unit_price' => 500, 'tax_type' => TaxType::Exento],
            ['source' => RepairItemSource::PiezaInventario, 'product_id' => $product->id, 'unit_price' => 1150, 'tax_type' => TaxType::Gravado15],
        ]);

        $delivered = $this->service->deliver(
            repair: $repair,
            paymentMethod: PaymentMethod::Efectivo,
        );

        // Estado y vínculos
        $this->assertEquals(RepairStatus::Entregada, $delivered->status);
        $this->assertNotNull($delivered->delivered_at);
        $this->assertNotNull($delivered->sale_id);
        $this->assertNotNull($delivered->invoice_id);

        // Sale + Invoice creados
        $this->assertDatabaseHas('sales', ['id' => $delivered->sale_id, 'status' => 'completada']);
        $this->assertDatabaseHas('invoices', ['id' => $delivered->invoice_id]);

        // Stock descontado de la pieza interna
        $product->refresh();
        $this->assertEquals(4, $product->stock, 'Stock debió decrementar de 5 a 4.');

        // Movimiento de caja: ingreso por TOTAL (sin anticipo)
        $movement = CashMovement::where('reference_type', Repair::class)
            ->where('reference_id', $repair->id)
            ->where('type', CashMovementType::RepairFinalIncome->value)
            ->first();
        $this->assertNotNull($movement);
        $this->assertEquals(1650.00, (float) $movement->amount); // 500 + 1150
    }

    public function test_falla_sin_caja_abierta_si_paga_efectivo(): void
    {
        // Cerrar la caja
        app(CashSessionService::class)->close(
            session: $this->caja,
            closedBy: $this->cajero,
            actualClosingAmount: 1000.00,
        );

        $repair = $this->makeRepairListoEntrega([
            ['source' => RepairItemSource::HonorariosReparacion, 'unit_price' => 500, 'tax_type' => TaxType::Exento],
        ]);

        $this->expectException(NoHayCajaAbiertaException::class);

        $this->service->deliver(
            repair: $repair,
            paymentMethod: PaymentMethod::Efectivo,
        );

        // Verificar rollback: el repair sigue en ListoEntrega, sin Sale ni Invoice
        $repair->refresh();
        $this->assertEquals(RepairStatus::ListoEntrega, $repair->status);
        $this->assertNull($repair->sale_id);
        $this->assertNull($repair->invoice_id);
    }

    public function test_falla_si_pieza_interna_no_tiene_stock(): void
    {
        $product = $this->makeProduct(stock: 0);

        $repair = $this->makeRepairListoEntrega([
            ['source' => RepairItemSource::PiezaInventario, 'product_id' => $product->id, 'unit_price' => 1150, 'tax_type' => TaxType::Gravado15],
        ]);

        $this->expectException(InsufficientStockOnDeliveryException::class);

        try {
            $this->service->deliver(
                repair: $repair,
                paymentMethod: PaymentMethod::Efectivo,
            );
        } finally {
            // Rollback: el repair queda intacto, sin Sale ni Invoice
            $repair->refresh();
            $this->assertEquals(RepairStatus::ListoEntrega, $repair->status);
            $this->assertNull($repair->sale_id);
            $this->assertEquals(0, Sale::count());
            $this->assertEquals(0, Invoice::count());
        }
    }

    public function test_anticipo_se_descuenta_del_saldo_cobrado_en_caja(): void
    {
        $repair = $this->makeRepairListoEntrega(
            items: [
                ['source' => RepairItemSource::HonorariosReparacion, 'unit_price' => 1000, 'tax_type' => TaxType::Exento],
            ],
            advance: 300, // anticipo cobrado al aprobar
        );

        $this->service->deliver(
            repair: $repair,
            paymentMethod: PaymentMethod::Efectivo,
        );

        // El movimiento al entregar debe ser por SALDO (1000 - 300 = 700), NO por total
        $movement = CashMovement::where('reference_type', Repair::class)
            ->where('reference_id', $repair->id)
            ->where('type', CashMovementType::RepairFinalIncome->value)
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals(700.00, (float) $movement->amount);
    }

    public function test_excedente_de_anticipo_se_devuelve(): void
    {
        // Caso edge: total bajó después de cobrar anticipo. Anticipo > total.
        $repair = $this->makeRepairListoEntrega(
            items: [
                ['source' => RepairItemSource::HonorariosReparacion, 'unit_price' => 400, 'tax_type' => TaxType::Exento],
            ],
            advance: 500,
        );

        $this->service->deliver(
            repair: $repair,
            paymentMethod: PaymentMethod::Efectivo,
        );

        // No debe haber RepairFinalIncome (saldo es 0)
        $finalIncome = CashMovement::where('reference_id', $repair->id)
            ->where('type', CashMovementType::RepairFinalIncome->value)
            ->first();
        $this->assertNull($finalIncome);

        // Debe haber RepairAdvanceRefund por 100 (500 - 400)
        $refund = CashMovement::where('reference_id', $repair->id)
            ->where('type', CashMovementType::RepairAdvanceRefund->value)
            ->first();
        $this->assertNotNull($refund);
        $this->assertEquals(100.00, (float) $refund->amount);
    }

    public function test_items_mixtos_preservan_tax_type_por_linea(): void
    {
        $repair = $this->makeRepairListoEntrega([
            ['source' => RepairItemSource::HonorariosReparacion, 'unit_price' => 500, 'tax_type' => TaxType::Exento],
            ['source' => RepairItemSource::PiezaExterna, 'condition' => RepairItemCondition::Nueva, 'unit_price' => 2300, 'tax_type' => TaxType::Gravado15],
            ['source' => RepairItemSource::PiezaExterna, 'condition' => RepairItemCondition::Usada, 'unit_price' => 200, 'tax_type' => TaxType::Exento],
        ]);

        $delivered = $this->service->deliver(
            repair: $repair,
            paymentMethod: PaymentMethod::Efectivo,
        );

        $sale = Sale::find($delivered->sale_id);
        $sale->load('items');

        // Cada SaleItem preserva su tax_type
        $exempt = $sale->items->where('tax_type', TaxType::Exento)->count();
        $gravado = $sale->items->where('tax_type', TaxType::Gravado15)->count();
        $this->assertEquals(2, $exempt, 'Honorarios + pieza usada deben ser exentos.');
        $this->assertEquals(1, $gravado, 'Pieza nueva externa debe ser gravada.');
    }

    public function test_sin_rtn_emite_factura_a_consumidor_final(): void
    {
        $repair = $this->makeRepairListoEntrega([
            ['source' => RepairItemSource::HonorariosReparacion, 'unit_price' => 500, 'tax_type' => TaxType::Exento],
        ]);

        // Borrar el RTN del repair para forzar consumidor final
        $repair->update(['customer_rtn' => null]);

        $delivered = $this->service->deliver(
            repair: $repair,
            paymentMethod: PaymentMethod::Efectivo,
        );

        $invoice = Invoice::find($delivered->invoice_id);
        $this->assertNull($invoice->customer_rtn);
        $this->assertNotNull($invoice->customer_name);
    }

    public function test_idempotencia_no_se_puede_entregar_dos_veces(): void
    {
        $repair = $this->makeRepairListoEntrega([
            ['source' => RepairItemSource::HonorariosReparacion, 'unit_price' => 500, 'tax_type' => TaxType::Exento],
        ]);

        $this->service->deliver(
            repair: $repair,
            paymentMethod: PaymentMethod::Efectivo,
        );

        // Segunda llamada debe fallar — ya está Entregada, no permite transición.
        $this->expectException(RepairTransitionException::class);

        $this->service->deliver(
            repair: $repair->fresh(),
            paymentMethod: PaymentMethod::Efectivo,
        );

        // Solo una Sale y una Invoice creadas
        $this->assertEquals(1, Sale::count());
        $this->assertEquals(1, Invoice::count());
    }

    public function test_sin_items_falla_con_repair_delivery_exception(): void
    {
        $repair = Repair::factory()
            ->readyForDelivery()
            ->state([
                'establishment_id' => $this->matriz->id,
                'device_category_id' => $this->deviceCategory->id,
            ])
            ->create();

        // No agregamos items intencionalmente.

        $this->expectException(RepairDeliveryException::class);
        $this->expectExceptionMessage('sin líneas de cotización');

        $this->service->deliver(
            repair: $repair,
            paymentMethod: PaymentMethod::Efectivo,
        );
    }
}

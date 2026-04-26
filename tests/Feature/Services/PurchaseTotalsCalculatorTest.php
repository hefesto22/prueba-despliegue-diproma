<?php

namespace Tests\Feature\Services;

use App\Enums\ProductCondition;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Enums\TaxType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Services\Purchases\PurchaseTotalsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Tests dedicados al PurchaseTotalsCalculator.
 *
 * El Calculator es la fuente única de verdad para la aritmética fiscal
 * de compras (subtotal, taxable_total, exempt_total, isv, total). Esta
 * batería garantiza:
 *
 *  1. Separación correcta gravado/exento — requerido por Libro de Compras SAR.
 *  2. Redondeo a 2 decimales en todos los acumuladores.
 *  3. Multiplicación por cantidad sin errores de coma flotante.
 *  4. updateQuietly no dispara Activity Log (un recálculo no es evento fiscal).
 *  5. Comportamiento correcto ante compra sin items.
 *
 * Convención del dominio: `unit_cost` llega con ISV incluido. La base se
 * deriva dividiendo entre `config('tax.multiplier')` (15% → 1.15).
 */
class PurchaseTotalsCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseTotalsCalculator $calculator;
    private Supplier $supplier;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = app(PurchaseTotalsCalculator::class);
        $this->supplier = Supplier::factory()->create();
        $this->category = Category::factory()->create();
    }

    /**
     * Helper: producto gravado (nuevo) o exento (usado) según se requiera.
     */
    private function makeProduct(TaxType $taxType = TaxType::Gravado15, float $costPrice = 1000): Product
    {
        return Product::factory()->inCategory($this->category)->create([
            'cost_price' => $costPrice,
            'stock' => 10,
            'condition' => $taxType === TaxType::Gravado15 ? ProductCondition::New : ProductCondition::Used,
            'tax_type' => $taxType,
        ]);
    }

    /**
     * Helper: compra en borrador con los items descritos.
     * Cada item: ['product' => Product, 'quantity' => int, 'unit_cost' => float].
     */
    private function makePurchaseWithItems(array $items): Purchase
    {
        $purchase = Purchase::factory()->fromSupplier($this->supplier)->create([
            'date' => now(),
            'status' => PurchaseStatus::Borrador,
        ]);

        foreach ($items as $item) {
            PurchaseItem::factory()->forPurchase($purchase)->create([
                'product_id' => $item['product']->id,
                'quantity' => $item['quantity'],
                'unit_cost' => $item['unit_cost'],
                'tax_type' => $item['product']->tax_type,
            ]);
        }

        return $purchase->load('items');
    }

    // ─── Separación gravado/exento ─────────────────────────────

    public function test_all_gravado_accumulates_in_taxable_total(): void
    {
        // 1 item gravado: 1 × L1,150 (con ISV) → base 1,000 + ISV 150
        $product = $this->makeProduct(TaxType::Gravado15);
        $purchase = $this->makePurchaseWithItems([
            ['product' => $product, 'quantity' => 1, 'unit_cost' => 1150],
        ]);

        $this->calculator->recalculate($purchase);
        $purchase->refresh();

        $this->assertEquals(1000.00, (float) $purchase->taxable_total);
        $this->assertEquals(0.00, (float) $purchase->exempt_total);
        $this->assertEquals(150.00, (float) $purchase->isv);
        $this->assertEquals(1000.00, (float) $purchase->subtotal);
        $this->assertEquals(1150.00, (float) $purchase->total);
    }

    public function test_all_exento_accumulates_in_exempt_total(): void
    {
        // 1 item exento: 1 × L500 — sin ISV, base = total
        $product = $this->makeProduct(TaxType::Exento);
        $purchase = $this->makePurchaseWithItems([
            ['product' => $product, 'quantity' => 1, 'unit_cost' => 500],
        ]);

        $this->calculator->recalculate($purchase);
        $purchase->refresh();

        $this->assertEquals(0.00, (float) $purchase->taxable_total);
        $this->assertEquals(500.00, (float) $purchase->exempt_total);
        $this->assertEquals(0.00, (float) $purchase->isv);
        $this->assertEquals(500.00, (float) $purchase->subtotal);
        $this->assertEquals(500.00, (float) $purchase->total);
    }

    /**
     * Caso clave para el Libro de Compras SAR: una compra con productos
     * gravados Y exentos debe reportar los dos totales por separado.
     */
    public function test_mixed_items_separates_taxable_and_exempt(): void
    {
        $gravado = $this->makeProduct(TaxType::Gravado15);
        $exento = $this->makeProduct(TaxType::Exento);

        $purchase = $this->makePurchaseWithItems([
            ['product' => $gravado, 'quantity' => 1, 'unit_cost' => 1150], // base 1000, isv 150
            ['product' => $exento, 'quantity' => 1, 'unit_cost' => 500],   // base 500, sin isv
        ]);

        $this->calculator->recalculate($purchase);
        $purchase->refresh();

        $this->assertEquals(1000.00, (float) $purchase->taxable_total);
        $this->assertEquals(500.00, (float) $purchase->exempt_total);
        $this->assertEquals(150.00, (float) $purchase->isv);
        $this->assertEquals(1500.00, (float) $purchase->subtotal); // 1000 + 500
        $this->assertEquals(1650.00, (float) $purchase->total);    // 1500 + 150
    }

    public function test_multiple_gravado_items_sum_taxable(): void
    {
        $gravado1 = $this->makeProduct(TaxType::Gravado15);
        $gravado2 = $this->makeProduct(TaxType::Gravado15);

        $purchase = $this->makePurchaseWithItems([
            ['product' => $gravado1, 'quantity' => 1, 'unit_cost' => 1150],
            ['product' => $gravado2, 'quantity' => 1, 'unit_cost' => 2300], // base 2000, isv 300
        ]);

        $this->calculator->recalculate($purchase);
        $purchase->refresh();

        $this->assertEquals(3000.00, (float) $purchase->taxable_total);
        $this->assertEquals(0.00, (float) $purchase->exempt_total);
        $this->assertEquals(450.00, (float) $purchase->isv);
        $this->assertEquals(3450.00, (float) $purchase->total);
    }

    public function test_multiple_exento_items_sum_exempt(): void
    {
        $exento1 = $this->makeProduct(TaxType::Exento);
        $exento2 = $this->makeProduct(TaxType::Exento);

        $purchase = $this->makePurchaseWithItems([
            ['product' => $exento1, 'quantity' => 1, 'unit_cost' => 800],
            ['product' => $exento2, 'quantity' => 1, 'unit_cost' => 1200],
        ]);

        $this->calculator->recalculate($purchase);
        $purchase->refresh();

        $this->assertEquals(0.00, (float) $purchase->taxable_total);
        $this->assertEquals(2000.00, (float) $purchase->exempt_total);
        $this->assertEquals(0.00, (float) $purchase->isv);
        $this->assertEquals(2000.00, (float) $purchase->total);
    }

    // ─── Multiplicación por cantidad ───────────────────────────

    public function test_quantity_multiplies_line_correctly(): void
    {
        $product = $this->makeProduct(TaxType::Gravado15);

        // 5 unidades × L1,150 c/u = L5,750 total
        // Base: 5,750 / 1.15 = 5,000. ISV: 750.
        $purchase = $this->makePurchaseWithItems([
            ['product' => $product, 'quantity' => 5, 'unit_cost' => 1150],
        ]);

        $this->calculator->recalculate($purchase);
        $purchase->refresh();

        $this->assertEquals(5000.00, (float) $purchase->taxable_total);
        $this->assertEquals(750.00, (float) $purchase->isv);
        $this->assertEquals(5750.00, (float) $purchase->total);
    }

    // ─── Snapshot por línea ────────────────────────────────────

    /**
     * El Calculator no solo acumula totales a nivel compra — también
     * persiste subtotal/isv_amount/total en cada PurchaseItem. Esto es
     * lo que consume el PDF de la compra y el kardex cuando necesita
     * reconstruir el detalle histórico.
     */
    public function test_persists_per_line_subtotal_isv_and_total(): void
    {
        $gravado = $this->makeProduct(TaxType::Gravado15);
        $exento = $this->makeProduct(TaxType::Exento);

        $purchase = $this->makePurchaseWithItems([
            ['product' => $gravado, 'quantity' => 2, 'unit_cost' => 1150],
            ['product' => $exento, 'quantity' => 3, 'unit_cost' => 500],
        ]);

        $this->calculator->recalculate($purchase);
        $purchase->load('items');

        $gravadoLine = $purchase->items->firstWhere('product_id', $gravado->id);
        $exentoLine = $purchase->items->firstWhere('product_id', $exento->id);

        // Línea gravada: 2 × 1,150 = 2,300 total. Base: 2,000. ISV: 300.
        $this->assertEquals(2000.00, (float) $gravadoLine->subtotal);
        $this->assertEquals(300.00, (float) $gravadoLine->isv_amount);
        $this->assertEquals(2300.00, (float) $gravadoLine->total);

        // Línea exenta: 3 × 500 = 1,500. Base = total. ISV: 0.
        $this->assertEquals(1500.00, (float) $exentoLine->subtotal);
        $this->assertEquals(0.00, (float) $exentoLine->isv_amount);
        $this->assertEquals(1500.00, (float) $exentoLine->total);
    }

    // ─── updateQuietly no emite Activity Log ───────────────────

    /**
     * Un recálculo no es un evento fiscalmente auditable — solo confirm/cancel
     * son eventos auditables. Si `recalculate()` usara `update()` en vez de
     * `updateQuietly()`, cada edición en el Form registraría una entrada en
     * el log y contaminaría la historia real de la compra.
     */
    public function test_recalculate_does_not_create_activity_log_entry(): void
    {
        $product = $this->makeProduct(TaxType::Gravado15);
        $purchase = $this->makePurchaseWithItems([
            ['product' => $product, 'quantity' => 1, 'unit_cost' => 1150],
        ]);

        $activityCountBefore = Activity::where('subject_type', Purchase::class)
            ->where('subject_id', $purchase->id)
            ->count();

        $this->calculator->recalculate($purchase);

        $activityCountAfter = Activity::where('subject_type', Purchase::class)
            ->where('subject_id', $purchase->id)
            ->count();

        $this->assertEquals(
            $activityCountBefore,
            $activityCountAfter,
            'recalculate() debe usar updateQuietly para no ensuciar el activity log.'
        );
    }

    // ─── Borde: compra sin items ───────────────────────────────

    public function test_handles_purchase_with_no_items(): void
    {
        $purchase = Purchase::factory()->fromSupplier($this->supplier)->create([
            'date' => now(),
            'status' => PurchaseStatus::Borrador,
        ])->load('items');

        $this->calculator->recalculate($purchase);
        $purchase->refresh();

        $this->assertEquals(0.00, (float) $purchase->subtotal);
        $this->assertEquals(0.00, (float) $purchase->taxable_total);
        $this->assertEquals(0.00, (float) $purchase->exempt_total);
        $this->assertEquals(0.00, (float) $purchase->isv);
        $this->assertEquals(0.00, (float) $purchase->total);
    }

    // ─── Invariante fiscal: subtotal = taxable + exempt ────────

    /**
     * La identidad `subtotal = taxable_total + exempt_total` debe mantenerse
     * siempre — es lo que garantiza que el Libro de Compras cuadra con la
     * base de cálculo del formulario ISV-353.
     */
    public function test_invariant_subtotal_equals_taxable_plus_exempt(): void
    {
        $gravado = $this->makeProduct(TaxType::Gravado15);
        $exento = $this->makeProduct(TaxType::Exento);

        $purchase = $this->makePurchaseWithItems([
            ['product' => $gravado, 'quantity' => 4, 'unit_cost' => 2875], // base 2500/u × 4 = 10000
            ['product' => $exento, 'quantity' => 2, 'unit_cost' => 750],   // 1500
        ]);

        $this->calculator->recalculate($purchase);
        $purchase->refresh();

        $this->assertEquals(
            (float) $purchase->subtotal,
            (float) $purchase->taxable_total + (float) $purchase->exempt_total,
            'subtotal debe ser exactamente taxable_total + exempt_total'
        );

        // Y la otra invariante: total = subtotal + isv
        $this->assertEquals(
            (float) $purchase->total,
            (float) $purchase->subtotal + (float) $purchase->isv,
            'total debe ser exactamente subtotal + isv'
        );
    }

    // ─── Recibo Interno: NO separa ISV ─────────────────────────
    //
    // El RI es para compras informales sin documento SAR. No genera crédito
    // fiscal y no entra al Libro de Compras. La regla
    // SupplierDocumentType::separatesIsv() decide; el calculator la respeta
    // aunque el producto del catálogo sea Gravado 15%.

    /**
     * Helper: compra RI en borrador con los items descritos. Misma firma
     * que makePurchaseWithItems pero forzando document_type=ReciboInterno.
     */
    private function makeRIWithItems(array $items): Purchase
    {
        $purchase = Purchase::factory()->fromSupplier($this->supplier)->create([
            'date' => now(),
            'status' => PurchaseStatus::Borrador,
            'document_type' => SupplierDocumentType::ReciboInterno,
        ]);

        foreach ($items as $item) {
            PurchaseItem::factory()->forPurchase($purchase)->create([
                'product_id' => $item['product']->id,
                'quantity' => $item['quantity'],
                'unit_cost' => $item['unit_cost'],
                'tax_type' => $item['product']->tax_type,
            ]);
        }

        return $purchase->load('items');
    }

    public function test_recibo_interno_con_producto_gravado_no_separa_isv(): void
    {
        // L 100 c/u en RI: el operador pagó L 100 efectivos al proveedor
        // informal. NO se aplica back-out aunque el producto sea Gravado15.
        // Total = subtotal = 100, ISV = 0.
        $product = $this->makeProduct(TaxType::Gravado15);
        $purchase = $this->makeRIWithItems([
            ['product' => $product, 'quantity' => 1, 'unit_cost' => 100],
        ]);

        $this->calculator->recalculate($purchase);
        $purchase->refresh();

        $this->assertEquals(100.00, (float) $purchase->subtotal,
            'RI: subtotal debe ser igual al precio pagado (sin back-out de ISV)');
        $this->assertEquals(100.00, (float) $purchase->total,
            'RI: total debe ser igual al precio pagado');
        $this->assertEquals(0.00, (float) $purchase->isv,
            'RI: ISV siempre debe ser 0 — no hay crédito fiscal en compras informales');
        $this->assertEquals(0.00, (float) $purchase->taxable_total,
            'RI: taxable_total debe ser 0 — el Libro de Compras no incluye RIs');
        $this->assertEquals(100.00, (float) $purchase->exempt_total,
            'RI: la base entera va a exempt_total');
    }

    public function test_recibo_interno_persiste_subtotal_igual_a_total_en_item(): void
    {
        // El snapshot por línea también debe reflejar la regla: subtotal=total,
        // isv_amount=0. De aquí lee el accessor unit_cost_base de PurchaseItem.
        $product = $this->makeProduct(TaxType::Gravado15);
        $purchase = $this->makeRIWithItems([
            ['product' => $product, 'quantity' => 5, 'unit_cost' => 200],
        ]);

        $this->calculator->recalculate($purchase);
        $purchase->load('items');

        $line = $purchase->items->first();

        $this->assertEquals(1000.00, (float) $line->subtotal,
            'RI item: subtotal = quantity × unit_cost (sin back-out)');
        $this->assertEquals(0.00, (float) $line->isv_amount,
            'RI item: isv_amount siempre 0');
        $this->assertEquals(1000.00, (float) $line->total,
            'RI item: total igual a subtotal');
    }

    public function test_recibo_interno_con_producto_exento_se_comporta_igual(): void
    {
        // Regresión: producto exento en RI sigue dando isv=0 y subtotal=total.
        // No debe romperse el caso "exento + RI" que ya funcionaba.
        $product = $this->makeProduct(TaxType::Exento);
        $purchase = $this->makeRIWithItems([
            ['product' => $product, 'quantity' => 1, 'unit_cost' => 500],
        ]);

        $this->calculator->recalculate($purchase);
        $purchase->refresh();

        $this->assertEquals(500.00, (float) $purchase->subtotal);
        $this->assertEquals(500.00, (float) $purchase->total);
        $this->assertEquals(0.00, (float) $purchase->isv);
        $this->assertEquals(0.00, (float) $purchase->taxable_total);
        $this->assertEquals(500.00, (float) $purchase->exempt_total);
    }

    // ─── calculateLineFigures (static): respeta document_type ──

    public function test_calculate_line_figures_factura_aplica_back_out(): void
    {
        // Regresión: sin pasar document_type, debe aplicar back-out (default
        // legacy). Y con document_type=Factura debe hacer lo mismo.
        $sinDocType = PurchaseTotalsCalculator::calculateLineFigures(
            unitCost: 1150,
            quantity: 1,
            taxType: TaxType::Gravado15,
        );

        $conFactura = PurchaseTotalsCalculator::calculateLineFigures(
            unitCost: 1150,
            quantity: 1,
            taxType: TaxType::Gravado15,
            documentType: SupplierDocumentType::Factura,
        );

        $this->assertSame([1000.00, 150.00, 1150.00], [$sinDocType[0], $sinDocType[1], $sinDocType[2]],
            'Sin document_type: comportamiento legacy (back-out aplicado en gravado)');
        $this->assertSame([1000.00, 150.00, 1150.00], [$conFactura[0], $conFactura[1], $conFactura[2]],
            'Factura con gravado: back-out aplicado');
    }

    public function test_calculate_line_figures_recibo_interno_no_aplica_back_out(): void
    {
        // L 1,150 en RI con producto gravado: NO se separa. Subtotal=total.
        $resultado = PurchaseTotalsCalculator::calculateLineFigures(
            unitCost: 1150,
            quantity: 1,
            taxType: TaxType::Gravado15,
            documentType: SupplierDocumentType::ReciboInterno,
        );

        $this->assertSame([1150.00, 0.00, 1150.00], [$resultado[0], $resultado[1], $resultado[2]],
            'RI con gravado: subtotal=total, isv=0 (sin back-out)');
    }
}

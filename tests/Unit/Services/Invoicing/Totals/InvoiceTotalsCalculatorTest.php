<?php

namespace Tests\Unit\Services\Invoicing\Totals;

use App\Enums\TaxType;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Invoicing\Totals\InvoiceTotalsCalculator;
use App\Services\Invoicing\Totals\InvoiceTotalsResult;
use App\Services\Sales\Tax\SaleTaxCalculator;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unitarios puros del {@see InvoiceTotalsCalculator}.
 *
 * NO usan RefreshDatabase ni el container de Laravel. Construyen Sale y
 * SaleItem en memoria con `setRelation('items', ...)` para evitar tocar
 * MySQL. Esto los vuelve ~100x más rápidos que un feature test y permite
 * probar matemática fiscal sin dependencias.
 *
 * Cubre los 5 invariantes críticos del calculator:
 *   1. all-gravado: taxable_total = subtotal, exempt_total = 0
 *   2. all-exento:  exempt_total = subtotal, taxable_total = 0
 *   3. mix gravado+exento: segregación correcta por TaxType
 *   4. con descuento: ratio aplicado proporcionalmente a cada bucket
 *   5. invariante: taxable_total + exempt_total == subtotal (±0.01)
 *
 * El bug histórico (E.2.A4) era que `taxable_total` persistía
 * `Sale.subtotal` (gravado + exento sumados). Estos tests validan que el
 * calculator NUNCA caiga en ese error: aunque le pasemos un Sale con
 * items mixtos, la salida debe segregar correctamente.
 */
class InvoiceTotalsCalculatorTest extends TestCase
{
    private InvoiceTotalsCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        // Inyectamos SaleTaxCalculator real con multiplier=1.15 (ISV Honduras).
        // El calculator delega per-línea al SaleTaxCalculator — no lo mockeamos
        // para validar que la integración real produzca los números correctos.
        $this->calculator = new InvoiceTotalsCalculator(new SaleTaxCalculator(1.15));
    }

    /**
     * Helper: arma un Sale en memoria con items y totales ya seteados.
     * Usa `setRelation` para evitar persistir nada — tests puros sin DB.
     *
     * @param  array<int, array{unit_price: float, quantity: int, tax_type: TaxType}>  $items
     */
    private function makeSale(array $items, float $subtotal, float $isv, float $total, float $discount = 0.0): Sale
    {
        $sale = new Sale();
        $sale->subtotal = $subtotal;
        $sale->isv = $isv;
        $sale->total = $total;
        $sale->discount_amount = $discount;

        $itemModels = [];
        foreach ($items as $i => $data) {
            $item = new SaleItem();
            $item->id = $i + 1; // identity para TaxableLine
            $item->unit_price = $data['unit_price'];
            $item->quantity = $data['quantity'];
            $item->tax_type = $data['tax_type'];
            $itemModels[] = $item;
        }

        $sale->setRelation('items', new Collection($itemModels));

        return $sale;
    }

    #[Test]
    public function all_gravado_segrega_taxable_total_correctamente(): void
    {
        // 2 items gravados: 1150 c/u (con ISV) → base 1000 + isv 150 c/u
        // Total esperado: subtotal=2000, isv=300, total=2300
        $sale = $this->makeSale(
            items: [
                ['unit_price' => 1150, 'quantity' => 1, 'tax_type' => TaxType::Gravado15],
                ['unit_price' => 1150, 'quantity' => 1, 'tax_type' => TaxType::Gravado15],
            ],
            subtotal: 2000.00,
            isv: 300.00,
            total: 2300.00,
        );

        $result = $this->calculator->calculate($sale);

        $this->assertEquals(2000.00, $result->taxableTotal);
        $this->assertEquals(0.00, $result->exemptTotal);
        $this->assertEquals(2000.00, $result->subtotal);
        $this->assertEquals(300.00, $result->isv);
        $this->assertEquals(2300.00, $result->total);
    }

    #[Test]
    public function all_exento_segrega_exempt_total_correctamente(): void
    {
        // 1 item exento: unit_price=500 (sin ISV por exención) → base 500
        $sale = $this->makeSale(
            items: [
                ['unit_price' => 500, 'quantity' => 2, 'tax_type' => TaxType::Exento],
            ],
            subtotal: 1000.00,
            isv: 0.00,
            total: 1000.00,
        );

        $result = $this->calculator->calculate($sale);

        $this->assertEquals(0.00, $result->taxableTotal);
        $this->assertEquals(1000.00, $result->exemptTotal);
        $this->assertEquals(1000.00, $result->subtotal);
        $this->assertEquals(0.00, $result->isv);
        $this->assertEquals(1000.00, $result->total);
    }

    #[Test]
    public function mix_gravado_y_exento_segrega_buckets_correctamente(): void
    {
        // ESTE ES EL CASO QUE GATILLABA EL BUG E.2.A4:
        //   - Item gravado: 1150 (con ISV) → base 1000 + isv 150
        //   - Item exento:  500 (sin ISV)  → base 500
        //   - subtotal = 1500 (suma de bases)
        //   - total    = 1650 (subtotal + isv)
        //
        // Antes del fix, taxable_total persistía 1500 (gravado + exento juntos).
        // Ahora debe persistir 1000 (solo gravado) y exempt_total 500.
        $sale = $this->makeSale(
            items: [
                ['unit_price' => 1150, 'quantity' => 1, 'tax_type' => TaxType::Gravado15],
                ['unit_price' => 500,  'quantity' => 1, 'tax_type' => TaxType::Exento],
            ],
            subtotal: 1500.00,
            isv: 150.00,
            total: 1650.00,
        );

        $result = $this->calculator->calculate($sale);

        $this->assertEquals(1000.00, $result->taxableTotal, 'taxable_total debe ser SOLO gravado');
        $this->assertEquals(500.00, $result->exemptTotal, 'exempt_total debe ser SOLO exento');
        $this->assertEquals(1500.00, $result->subtotal);
        $this->assertEquals(150.00, $result->isv);
        $this->assertEquals(1650.00, $result->total);
    }

    #[Test]
    public function con_descuento_aplica_ratio_proporcionalmente_a_cada_bucket(): void
    {
        // Sale: 1 item gravado 1150 (base 1000) + 1 item exento 500 (base 500)
        //   gross = subtotal + isv + discount = 1500 + 150 + ... wait
        //   Recordatorio: la fórmula correcta es gross = Sale.total + Sale.discount.
        //   Si Sale.total ya está post-descuento (650 sobre un original de 1650),
        //   entonces discount=1000 → gross = 650 + 1000 = 1650 → ratio = 1000/1650 ≈ 0.606
        //
        // Pero un descuento del 60% es atípico. Usemos un caso real: descuento de 165
        // (10% sobre 1650). Sale.total post-descuento = 1485, discount=165.
        //   gross = 1485 + 165 = 1650 ✓
        //   ratio = 165/1650 = 0.10
        //   taxable post = 1000 * 0.90 = 900.00
        //   exempt  post = 500  * 0.90 = 450.00
        //   isv     post = 150  * 0.90 = 135.00
        //   subtotal post = 900 + 450 = 1350
        //   total    post = 1350 + 135 = 1485 ✓
        $sale = $this->makeSale(
            items: [
                ['unit_price' => 1150, 'quantity' => 1, 'tax_type' => TaxType::Gravado15],
                ['unit_price' => 500,  'quantity' => 1, 'tax_type' => TaxType::Exento],
            ],
            subtotal: 1350.00,  // post-descuento
            isv: 135.00,        // post-descuento
            total: 1485.00,     // post-descuento
            discount: 165.00,
        );

        $result = $this->calculator->calculate($sale);

        $this->assertEquals(900.00, $result->taxableTotal, 'taxable_total debe descontarse 10%');
        $this->assertEquals(450.00, $result->exemptTotal, 'exempt_total debe descontarse 10%');
        $this->assertEquals(135.00, $result->isv, 'isv debe descontarse 10%');
        $this->assertEquals(1350.00, $result->subtotal);
        $this->assertEquals(1485.00, $result->total);
    }

    #[Test]
    public function preserva_invariante_taxable_mas_exempt_igual_subtotal(): void
    {
        // Cualquier mix de items debe satisfacer:
        //   taxableTotal + exemptTotal == subtotal (tolerancia 0.01)
        // Es el contrato fundamental documentado en InvoiceTotalsResult.
        $sale = $this->makeSale(
            items: [
                ['unit_price' => 1150, 'quantity' => 3, 'tax_type' => TaxType::Gravado15],
                ['unit_price' => 250,  'quantity' => 2, 'tax_type' => TaxType::Exento],
                ['unit_price' => 575,  'quantity' => 1, 'tax_type' => TaxType::Gravado15],
            ],
            subtotal: 4000.00,  // 3*1000 + 2*250 + 1*500 = 4000
            isv: 525.00,        // (3*150) + (1*75) = 525
            total: 4525.00,
        );

        $result = $this->calculator->calculate($sale);

        $this->assertEqualsWithDelta(
            $result->subtotal,
            $result->taxableTotal + $result->exemptTotal,
            0.01,
            'Invariante violado: taxable_total + exempt_total debe igualar subtotal'
        );
    }

    #[Test]
    public function sale_sin_items_retorna_resultado_vacio(): void
    {
        $sale = $this->makeSale(
            items: [],
            subtotal: 0.00,
            isv: 0.00,
            total: 0.00,
        );

        $result = $this->calculator->calculate($sale);

        $this->assertInstanceOf(InvoiceTotalsResult::class, $result);
        $this->assertEquals(0.00, $result->taxableTotal);
        $this->assertEquals(0.00, $result->exemptTotal);
        $this->assertEquals(0.00, $result->subtotal);
        $this->assertEquals(0.00, $result->isv);
        $this->assertEquals(0.00, $result->total);
    }

    #[Test]
    public function descuento_cero_no_aplica_ratio(): void
    {
        // Defensa contra division por cero: discount=0 debe ir por la rama
        // de "normalización defensiva" (round sin tocar valores).
        $sale = $this->makeSale(
            items: [
                ['unit_price' => 1150, 'quantity' => 1, 'tax_type' => TaxType::Gravado15],
            ],
            subtotal: 1000.00,
            isv: 150.00,
            total: 1150.00,
            discount: 0.00,
        );

        $result = $this->calculator->calculate($sale);

        $this->assertEquals(1000.00, $result->taxableTotal);
        $this->assertEquals(0.00, $result->exemptTotal);
        $this->assertEquals(150.00, $result->isv);
    }
}

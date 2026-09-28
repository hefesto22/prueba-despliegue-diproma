<?php

namespace Tests\Feature\Services\Inventory;

use App\Enums\MovementType;
use App\Enums\TaxType;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\Inventory\ProductStockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Cobertura del asiento de Kardex que nace en la ficha del producto.
 *
 * Estos tests blindan el invariante central de la Fase 0: TODA unidad que
 * entra al inventario deja rastro. Antes de esta clase, crear un producto con
 * stock generaba existencias sin ningún movimiento que las respaldara.
 */
class ProductStockLedgerTest extends TestCase
{
    use CreatesMatriz;
    use RefreshDatabase;

    private Category $category;

    private ProductStockLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create();
        $this->ledger = app(ProductStockLedger::class);
    }

    private function makeProduct(int $stock = 10, float $costPrice = 1000): Product
    {
        return Product::factory()->inCategory($this->category)->create([
            'cost_price' => $costPrice,
            'stock' => $stock,
            'is_service' => false,
            'tax_type' => TaxType::Gravado15,
        ]);
    }

    // ─── Carga inicial ───────────────────────────────────────

    public function test_carga_inicial_registra_entrada_desde_cero(): void
    {
        $product = $this->makeProduct(stock: 7, costPrice: 4000);

        $movement = $this->ledger->recordInitialLoad($product);

        $this->assertNotNull($movement);
        $this->assertEquals(MovementType::AjusteEntrada, $movement->type);
        $this->assertEquals(7, $movement->quantity);
        $this->assertEquals('Carga inicial del producto', $movement->notes);

        // El punto de todo el override de stockBefore: el kardex de este
        // producto arranca en 0, no en el stock ya persistido.
        $this->assertEquals(0, $movement->stock_before);
        $this->assertEquals(7, $movement->stock_after);
    }

    public function test_carga_inicial_captura_el_costo_neto_del_producto(): void
    {
        $product = $this->makeProduct(stock: 3, costPrice: 4500.50);

        $movement = $this->ledger->recordInitialLoad($product);

        // unit_cost es NETO por convención del proyecto (ver docblock de Product).
        $this->assertEquals(4500.50, (float) $movement->unit_cost);
    }

    public function test_carga_inicial_se_atribuye_a_la_sucursal_resuelta(): void
    {
        $product = $this->makeProduct(stock: 2);

        $movement = $this->ledger->recordInitialLoad($product);

        $this->assertEquals($this->matriz->id, $movement->establishment_id);
    }

    public function test_carga_inicial_no_registra_nada_si_el_stock_es_cero(): void
    {
        $product = $this->makeProduct(stock: 0);

        $this->assertNull($this->ledger->recordInitialLoad($product));
        $this->assertEquals(0, InventoryMovement::count());
    }

    public function test_carga_inicial_ignora_servicios(): void
    {
        // Los servicios se persisten con stock 999999 como infinito práctico.
        // Meterlo al Kardex inflaría el valor del inventario en millones.
        $product = Product::factory()->inCategory($this->category)->create([
            'is_service' => true,
            'stock' => 999999,
            'tax_type' => TaxType::Exento,
        ]);

        $this->assertNull($this->ledger->recordInitialLoad($product));
        $this->assertEquals(0, InventoryMovement::count());
    }

    // ─── Ajuste manual ───────────────────────────────────────

    public function test_ajuste_manual_positivo_registra_entrada(): void
    {
        $product = $this->makeProduct(stock: 5);

        // Simula el estado post-save de Filament: el modelo ya trae el valor
        // nuevo y el caller aporta el anterior.
        $product->stock = 8;

        $movement = $this->ledger->recordManualAdjustment($product, previousStock: 5);

        $this->assertNotNull($movement);
        $this->assertEquals(MovementType::AjusteEntrada, $movement->type);
        $this->assertEquals(3, $movement->quantity);
        $this->assertEquals(5, $movement->stock_before);
        $this->assertEquals(8, $movement->stock_after);
        $this->assertStringContainsString('5 → 8', $movement->notes);
    }

    public function test_ajuste_manual_negativo_registra_salida(): void
    {
        $product = $this->makeProduct(stock: 5);
        $product->stock = 2;

        $movement = $this->ledger->recordManualAdjustment($product, previousStock: 5);

        $this->assertNotNull($movement);
        $this->assertEquals(MovementType::AjusteSalida, $movement->type);
        $this->assertEquals(3, $movement->quantity);
        $this->assertEquals(5, $movement->stock_before);
        $this->assertEquals(2, $movement->stock_after);
    }

    public function test_ajuste_manual_sin_cambio_no_registra_nada(): void
    {
        $product = $this->makeProduct(stock: 5);

        $this->assertNull($this->ledger->recordManualAdjustment($product, previousStock: 5));
        $this->assertEquals(0, InventoryMovement::count());
    }

    public function test_ajuste_manual_ignora_servicios(): void
    {
        $product = Product::factory()->inCategory($this->category)->create([
            'is_service' => true,
            'stock' => 999999,
            'tax_type' => TaxType::Exento,
        ]);
        $product->stock = 500;

        $this->assertNull($this->ledger->recordManualAdjustment($product, previousStock: 999999));
        $this->assertEquals(0, InventoryMovement::count());
    }

    // ─── Encadenamiento ──────────────────────────────────────

    public function test_carga_inicial_y_ajuste_encadenan_sin_huecos(): void
    {
        $product = $this->makeProduct(stock: 4);

        $inicial = $this->ledger->recordInitialLoad($product);

        $product->stock = 6;
        $ajuste = $this->ledger->recordManualAdjustment($product, previousStock: 4);

        // El stock_after de un movimiento debe ser el stock_before del siguiente.
        // Si esta cadena se rompe, el Kardex deja de ser auditable.
        $this->assertEquals(0, $inicial->stock_before);
        $this->assertEquals(4, $inicial->stock_after);
        $this->assertEquals(4, $ajuste->stock_before);
        $this->assertEquals(6, $ajuste->stock_after);
        $this->assertEquals(2, InventoryMovement::count());
    }
}

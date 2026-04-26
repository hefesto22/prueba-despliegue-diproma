<?php

namespace Tests\Feature\Models;

use App\Enums\MovementType;
use App\Enums\PurchaseStatus;
use App\Enums\TaxType;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchases\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

class InventoryMovementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMatriz;

    private Category $category;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = Category::factory()->create();
        $this->supplier = Supplier::factory()->create();
    }

    private function makeProduct(float $costPrice = 1000, int $stock = 10): Product
    {
        return Product::factory()->inCategory($this->category)->create([
            'cost_price' => $costPrice,
            'stock' => $stock,
            'tax_type' => TaxType::Gravado15,
        ]);
    }

    // ─── Tests del método record() ──────────────────────────

    public function test_record_creates_entry_movement_with_correct_stock(): void
    {
        $product = $this->makeProduct(stock: 10);

        $movement = InventoryMovement::record(
            product: $product,
            type: MovementType::EntradaCompra,
            quantity: 5,
        );

        $this->assertEquals($product->id, $movement->product_id);
        $this->assertEquals(MovementType::EntradaCompra, $movement->type);
        $this->assertEquals(5, $movement->quantity);
        $this->assertEquals(10, $movement->stock_before);
        $this->assertEquals(15, $movement->stock_after);
    }

    public function test_record_creates_exit_movement_with_correct_stock(): void
    {
        $product = $this->makeProduct(stock: 10);

        $movement = InventoryMovement::record(
            product: $product,
            type: MovementType::SalidaAnulacionCompra,
            quantity: 3,
        );

        $this->assertEquals(10, $movement->stock_before);
        $this->assertEquals(7, $movement->stock_after);
    }

    public function test_record_exit_never_goes_below_zero(): void
    {
        $product = $this->makeProduct(stock: 2);

        $movement = InventoryMovement::record(
            product: $product,
            type: MovementType::AjusteSalida,
            quantity: 5,
        );

        $this->assertEquals(2, $movement->stock_before);
        $this->assertEquals(0, $movement->stock_after);
    }

    public function test_record_saves_polymorphic_reference(): void
    {
        $product = $this->makeProduct(stock: 10);
        $purchase = Purchase::factory()->fromSupplier($this->supplier)->create();

        $movement = InventoryMovement::record(
            product: $product,
            type: MovementType::EntradaCompra,
            quantity: 5,
            reference: $purchase,
            notes: 'Test compra',
        );

        $this->assertEquals(Purchase::class, $movement->reference_type);
        $this->assertEquals($purchase->id, $movement->reference_id);
        $this->assertEquals('Test compra', $movement->notes);
    }

    public function test_record_without_reference_has_null_morphs(): void
    {
        $product = $this->makeProduct(stock: 10);

        $movement = InventoryMovement::record(
            product: $product,
            type: MovementType::AjusteEntrada,
            quantity: 3,
            notes: 'Conteo físico',
        );

        $this->assertNull($movement->reference_type);
        $this->assertNull($movement->reference_id);
    }

    // ─── Tests de scopes ────────────────────────────────────

    public function test_scope_entries_filters_correctly(): void
    {
        $product = $this->makeProduct();

        InventoryMovement::factory()->forProduct($product)->entradaCompra()->create();
        InventoryMovement::factory()->forProduct($product)->ajusteEntrada()->create();
        InventoryMovement::factory()->forProduct($product)->salidaAnulacion()->create();

        $entries = InventoryMovement::entries()->count();
        $this->assertEquals(2, $entries);
    }

    public function test_scope_exits_filters_correctly(): void
    {
        $product = $this->makeProduct();

        InventoryMovement::factory()->forProduct($product)->entradaCompra()->create();
        InventoryMovement::factory()->forProduct($product)->salidaAnulacion()->create();
        InventoryMovement::factory()->forProduct($product)->ajusteSalida()->create();

        $exits = InventoryMovement::exits()->count();
        $this->assertEquals(2, $exits);
    }

    public function test_scope_manual_filters_only_adjustments(): void
    {
        $product = $this->makeProduct();

        InventoryMovement::factory()->forProduct($product)->entradaCompra()->create();
        InventoryMovement::factory()->forProduct($product)->ajusteEntrada()->create();
        InventoryMovement::factory()->forProduct($product)->ajusteSalida()->create();

        $manual = InventoryMovement::manual()->count();
        $this->assertEquals(2, $manual);
    }

    public function test_scope_for_product_filters_by_product(): void
    {
        $product1 = $this->makeProduct();
        $product2 = $this->makeProduct();

        InventoryMovement::factory()->forProduct($product1)->count(3)->create();
        InventoryMovement::factory()->forProduct($product2)->count(2)->create();

        $this->assertEquals(3, InventoryMovement::forProduct($product1->id)->count());
        $this->assertEquals(2, InventoryMovement::forProduct($product2->id)->count());
    }

    // ─── Tests de auto-asignación created_by ────────────────

    public function test_auto_assigns_created_by_when_authenticated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = $this->makeProduct();

        $movement = InventoryMovement::record(
            product: $product,
            type: MovementType::AjusteEntrada,
            quantity: 1,
        );

        $this->assertEquals($user->id, $movement->created_by);
    }

    // ─── Tests de integración con PurchaseService ───────────

    public function test_confirm_purchase_creates_entry_movements(): void
    {
        $service = app(PurchaseService::class);
        $product = $this->makeProduct(costPrice: 1000, stock: 5);

        $purchase = Purchase::factory()->fromSupplier($this->supplier)->create([
            'date' => now(),
            'status' => PurchaseStatus::Borrador,
        ]);

        PurchaseItem::factory()->forPurchase($purchase)->create([
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_cost' => 1200,
            'tax_type' => TaxType::Gravado15,
        ]);

        $purchase->load('items');
        $service->confirm($purchase);

        // Debe existir un movimiento de entrada
        $movement = InventoryMovement::where('product_id', $product->id)
            ->where('type', MovementType::EntradaCompra)
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals(3, $movement->quantity);
        $this->assertEquals(5, $movement->stock_before);
        $this->assertEquals(8, $movement->stock_after);
        $this->assertEquals(Purchase::class, $movement->reference_type);
        $this->assertEquals($purchase->id, $movement->reference_id);
    }

    public function test_cancel_confirmed_purchase_creates_exit_movements(): void
    {
        $service = app(PurchaseService::class);
        $product = $this->makeProduct(costPrice: 1000, stock: 5);

        $purchase = Purchase::factory()->fromSupplier($this->supplier)->create([
            'date' => now(),
            'status' => PurchaseStatus::Borrador,
        ]);

        PurchaseItem::factory()->forPurchase($purchase)->create([
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_cost' => 1200,
            'tax_type' => TaxType::Gravado15,
        ]);

        $purchase->load('items');
        $service->confirm($purchase);

        // Ahora anular
        $service->cancel($purchase->refresh());

        // Deben existir 2 movimientos: 1 entrada + 1 salida
        $movements = InventoryMovement::where('product_id', $product->id)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $movements);
        $this->assertEquals(MovementType::EntradaCompra, $movements[0]->type);
        $this->assertEquals(MovementType::SalidaAnulacionCompra, $movements[1]->type);

        // La salida registra el stock antes de la reversión
        $exit = $movements[1];
        $this->assertEquals(3, $exit->quantity);
        $this->assertEquals(8, $exit->stock_before); // stock después de confirmar
    }

    public function test_cancel_borrador_does_not_create_movements(): void
    {
        $service = app(PurchaseService::class);
        $product = $this->makeProduct(costPrice: 1000, stock: 5);

        $purchase = Purchase::factory()->fromSupplier($this->supplier)->create([
            'date' => now(),
            'status' => PurchaseStatus::Borrador,
        ]);

        PurchaseItem::factory()->forPurchase($purchase)->create([
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_cost' => 1200,
            'tax_type' => TaxType::Gravado15,
        ]);

        $purchase->load('items');
        $service->cancel($purchase);

        // No debe haber movimientos
        $this->assertEquals(0, InventoryMovement::count());
    }

    // ─── Test: relación product→inventoryMovements ──────────

    public function test_product_has_inventory_movements_relationship(): void
    {
        $product = $this->makeProduct(stock: 10);

        InventoryMovement::record($product, MovementType::EntradaCompra, 5);
        InventoryMovement::record($product, MovementType::AjusteSalida, 2);

        $product->refresh();
        $this->assertEquals(2, $product->inventoryMovements()->count());

        // Ordenados por fecha descendente
        $movements = $product->inventoryMovements;
        $this->assertTrue($movements->first()->created_at >= $movements->last()->created_at);
    }
}

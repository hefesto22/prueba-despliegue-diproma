<?php

namespace Tests\Feature\Models;

use App\Enums\ProductCondition;
use App\Enums\ProductType;
use App\Enums\TaxType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_product_with_factory(): void
    {
        $product = Product::factory()->create();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertNotEmpty($product->sku);
        $this->assertNotEmpty($product->name);
    }

    public function test_used_products_are_automatically_exempt_from_isv(): void
    {
        $product = Product::factory()->create([
            'condition' => ProductCondition::Used,
        ]);

        $this->assertEquals(TaxType::Exento, $product->tax_type);
    }

    public function test_new_products_are_automatically_gravado(): void
    {
        $product = Product::factory()->create([
            'condition' => ProductCondition::New,
        ]);

        $this->assertEquals(TaxType::Gravado15, $product->tax_type);
    }

    public function test_isv_calculation_on_sale_price(): void
    {
        $product = Product::factory()->brandNew()->create([
            'sale_price' => 1000.00,
        ]);

        // 1000 * 0.15 = 150.00
        $this->assertEquals(150.00, $product->calculateSaleIsv());
    }

    public function test_exempt_product_has_zero_isv(): void
    {
        $product = Product::factory()->used()->create([
            'sale_price' => 1000.00,
        ]);

        $this->assertEquals(0.00, $product->calculateSaleIsv());
    }

    public function test_profit_margin_calculation(): void
    {
        $product = Product::factory()->create([
            'cost_price' => 1000.00,
            'sale_price' => 1500.00,
        ]);

        // (1500 - 1000) / 1000 * 100 = 50%
        $this->assertEquals(50.00, $product->profit_margin);
        $this->assertEquals(500.00, $product->profit_amount);
    }

    public function test_price_conversion_helpers(): void
    {
        // Base 1000 → Con ISV 1150
        $this->assertEquals(1150.00, Product::priceWithIsv(1000.00));

        // Con ISV 1150 → Base 1000
        $this->assertEquals(1000.00, Product::priceWithoutIsv(1150.00));
    }

    public function test_stock_status_helpers(): void
    {
        $lowStock = Product::factory()->lowStock()->create();
        $outOfStock = Product::factory()->outOfStock()->create();
        $normalStock = Product::factory()->create(['stock' => 100, 'min_stock' => 5]);

        $this->assertTrue($lowStock->isLowStock());
        $this->assertFalse($lowStock->isOutOfStock());

        $this->assertTrue($outOfStock->isOutOfStock());
        $this->assertFalse($outOfStock->isLowStock());

        $this->assertFalse($normalStock->isLowStock());
        $this->assertFalse($normalStock->isOutOfStock());
    }

    public function test_active_scope(): void
    {
        Product::factory()->count(3)->create(['is_active' => true]);
        Product::factory()->count(2)->inactive()->create();

        $this->assertCount(3, Product::active()->get());
    }

    public function test_sku_is_auto_generated(): void
    {
        $product = Product::factory()->ofType(ProductType::Laptop)->create([
            'brand' => 'HP',
        ]);

        $this->assertStringStartsWith('LAP-HP-', $product->sku);
    }

    public function test_category_relationship(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->inCategory($category)->create();

        $this->assertTrue($product->category->is($category));
        $this->assertCount(1, $category->products);
    }
}

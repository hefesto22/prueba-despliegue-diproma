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

    // ─── enforceTaxType: regla canónica derivada de is_service + condition ──
    //
    // Bug 2026-05-04 (productos físicos custom no-servicio): el form tenía
    // dos campos con key 'tax_type' (Hidden + Select) cuyos defaults se
    // pisaban entre sí. Para productos físicos como EQUIPO DE SEGURIDAD el
    // tax_type podía quedar en 'exento' aunque la condición fuera Nuevo.
    //
    // Fix: enforceTaxType ahora deriva de is_service + condition, ignorando
    // lo que envíe el form para productos físicos. Sólo respeta tax_type
    // del form para servicios (Select expuesto explícitamente al usuario).

    public function test_custom_no_servicio_nuevo_se_marca_gravado_aunque_form_diga_exento(): void
    {
        // EQUIPO DE SEGURIDAD: tipo custom, físico (is_service=false), Nuevo.
        // Aunque el form mande tax_type='exento' (bug del Select fantasma),
        // el modelo lo enforce a Gravado15 por la condición.
        $category = Category::factory()->create();
        $product = Product::factory()->inCategory($category)->create([
            'product_type' => 'EQUIPO DE SEGURIDAD',
            'condition' => ProductCondition::New,
            'is_service' => false,
            'tax_type' => TaxType::Exento, // ← contaminación del form, ignorada
        ]);

        $this->assertEquals(
            TaxType::Gravado15,
            $product->tax_type,
            'Custom no-servicio Nuevo: tax_type derivado de condition, NO del form.'
        );
    }

    public function test_custom_no_servicio_usado_se_marca_exento(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->inCategory($category)->create([
            'product_type' => 'EQUIPO DE SEGURIDAD',
            'condition' => ProductCondition::Used,
            'is_service' => false,
            'tax_type' => TaxType::Gravado15, // ← inconsistente, ignorado
        ]);

        $this->assertEquals(
            TaxType::Exento,
            $product->tax_type,
            'Custom no-servicio Usado: Exento por condición (Decreto 194-2002).'
        );
    }

    public function test_servicio_respeta_tax_type_del_form(): void
    {
        // Para servicios el Select 'Tipo fiscal' está expuesto al usuario,
        // así que respetamos lo que eligió. Honorarios típicamente Exento
        // pero podría haber servicios gravados (consultoría, etc.).
        $category = Category::factory()->create();

        $servicioExento = Product::factory()->inCategory($category)->create([
            'product_type' => 'HONORARIOS',
            'is_service' => true,
            'tax_type' => TaxType::Exento,
            'condition' => ProductCondition::New,
            'stock' => 999999,
        ]);
        $this->assertEquals(TaxType::Exento, $servicioExento->tax_type);

        $servicioGravado = Product::factory()->inCategory($category)->create([
            'product_type' => 'CONSULTORIA',
            'is_service' => true,
            'tax_type' => TaxType::Gravado15,
            'condition' => ProductCondition::New,
            'stock' => 999999,
        ]);
        $this->assertEquals(TaxType::Gravado15, $servicioGravado->tax_type);
    }

    public function test_servicio_sin_tax_type_default_exento(): void
    {
        // Si por algún motivo el form no envía tax_type para un servicio,
        // default Exento (caso típico de Honorarios).
        $category = Category::factory()->create();
        $product = Product::factory()->inCategory($category)->create([
            'product_type' => 'HONORARIOS',
            'is_service' => true,
            'tax_type' => null,
            'condition' => ProductCondition::New,
            'stock' => 999999,
        ]);

        $this->assertEquals(TaxType::Exento, $product->tax_type);
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

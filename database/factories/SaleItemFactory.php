<?php

namespace Database\Factories;

use App\Enums\TaxType;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SaleItem>
 */
class SaleItemFactory extends Factory
{
    protected $model = SaleItem::class;

    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 5),
            'unit_price' => fake()->randomFloat(2, 100, 5000),
            'tax_type' => TaxType::Gravado15,
            'subtotal' => 0,
            'isv_amount' => 0,
            'total' => 0,
        ];
    }

    /**
     * Para un producto específico.
     */
    public function forProduct(Product $product): static
    {
        return $this->state(fn () => [
            'product_id' => $product->id,
            'unit_price' => $product->sale_price,
            'tax_type' => $product->tax_type,
        ]);
    }

    /**
     * Para una venta específica.
     */
    public function forSale(Sale $sale): static
    {
        return $this->state(fn () => ['sale_id' => $sale->id]);
    }

    /**
     * Producto exento de ISV.
     */
    public function exento(): static
    {
        return $this->state(fn () => ['tax_type' => TaxType::Exento]);
    }
}

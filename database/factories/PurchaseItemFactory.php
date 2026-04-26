<?php

namespace Database\Factories;

use App\Enums\TaxType;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PurchaseItem>
 */
class PurchaseItemFactory extends Factory
{
    protected $model = PurchaseItem::class;

    public function definition(): array
    {
        $unitCost = fake()->randomFloat(2, 500, 30000);
        $quantity = fake()->numberBetween(1, 10);

        return [
            'purchase_id' => Purchase::factory(),
            'product_id' => Product::factory(),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'tax_type' => TaxType::Gravado15,
            'subtotal' => 0,     // Se recalcula por PurchaseService
            'isv_amount' => 0,   // Se recalcula por PurchaseService
            'total' => round($unitCost * $quantity, 2),
            'serial_numbers' => null,
        ];
    }

    /**
     * Item para un producto específico.
     */
    public function forProduct(Product $product): static
    {
        return $this->state(fn () => [
            'product_id' => $product->id,
            'unit_cost' => $product->cost_price,
            'tax_type' => $product->tax_type,
        ]);
    }

    /**
     * Item para una compra específica.
     */
    public function forPurchase(Purchase $purchase): static
    {
        return $this->state(fn () => ['purchase_id' => $purchase->id]);
    }

    /**
     * Item exento de ISV.
     */
    public function exento(): static
    {
        return $this->state(fn () => ['tax_type' => TaxType::Exento]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\ProductCondition;
use App\Enums\ProductType;
use App\Enums\TaxType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $type = fake()->randomElement(ProductType::cases());
        $condition = fake()->randomElement(ProductCondition::cases());
        $taxType = $condition === ProductCondition::Used ? TaxType::Exento : TaxType::Gravado15;

        $costPrice = fake()->randomFloat(2, 500, 50000);
        $margin = fake()->randomFloat(2, 1.10, 1.50); // 10-50% de margen
        $salePrice = round($costPrice * $margin, 2);

        return [
            // name, slug y sku NO se definen aqui — Product::booted() los
            // autogenera en `creating` a partir de type + brand + model + specs.
            // Cualquier valor que intentemos pasar se sobreescribe y confunde
            // al lector del factory. Si un test necesita un name especifico
            // hay que forzarlo via ->state([...]) despues del create.
            'description' => fake()->optional(0.3)->sentence(),
            'category_id' => Category::factory(),
            'product_type' => $type,
            'brand' => fake()->randomElement(['HP', 'DELL', 'LENOVO', 'ASUS', 'ACER', 'SONY', 'SAMSUNG', 'LG', 'EPSON', 'LOGITECH']),
            'model' => strtoupper(fake()->bothify('???-####')),
            'condition' => $condition,
            'tax_type' => $taxType,
            'cost_price' => $costPrice,
            'sale_price' => $salePrice,
            'stock' => fake()->numberBetween(0, 50),
            'min_stock' => fake()->numberBetween(1, 5),
            'specs' => [],
            'serial_numbers' => null,
            'image_path' => null,
            'is_active' => true,
        ];
    }

    /**
     * Producto nuevo (gravado 15%).
     * Nota: No se puede llamar new() porque colisiona con Factory::new().
     */
    public function brandNew(): static
    {
        return $this->state(fn () => [
            'condition' => ProductCondition::New,
            'tax_type' => TaxType::Gravado15,
        ]);
    }

    /**
     * Producto usado (exento ISV).
     */
    public function used(): static
    {
        return $this->state(fn () => [
            'condition' => ProductCondition::Used,
            'tax_type' => TaxType::Exento,
        ]);
    }

    /**
     * Producto tipo específico.
     */
    public function ofType(ProductType $type): static
    {
        return $this->state(fn () => ['product_type' => $type]);
    }

    /**
     * Producto sin stock.
     */
    public function outOfStock(): static
    {
        return $this->state(fn () => ['stock' => 0]);
    }

    /**
     * Producto con stock bajo.
     */
    public function lowStock(): static
    {
        return $this->state(fn () => [
            'stock' => 2,
            'min_stock' => 5,
        ]);
    }

    /**
     * Producto inactivo.
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Asignar a una categoría existente.
     */
    public function inCategory(Category $category): static
    {
        return $this->state(fn () => ['category_id' => $category->id]);
    }
}

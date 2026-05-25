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
            // name y sku NO se definen aqui — Product::booted() los autogenera
            // en `creating` a partir de type + brand + model + specs.
            // Cualquier valor que intentemos pasar se sobreescribe y confunde
            // al lector del factory. Si un test necesita un name especifico
            // hay que forzarlo via ->state([...]) despues del create.
            'description' => fake()->optional(0.3)->sentence(),
            'category_id' => Category::factory(),
            // Para enum cases: guardamos el `value` en minúsculas (ej. 'laptop').
            // El modelo Product::normalizeProductType respeta este formato.
            'product_type' => $type->value,
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
     * Producto tipo específico (acepta enum o string custom).
     *
     * Enum: guarda el `value` (minúsculas — 'laptop', 'desktop').
     * String custom: guarda en MAYÚSCULAS (formato de spec_options).
     * Product::normalizeProductType en el modelo aplica la misma regla
     * en runtime, así que también acepta cualquier casing del input.
     */
    public function ofType(ProductType|string $type): static
    {
        $value = $type instanceof ProductType
            ? $type->value                       // 'laptop'
            : mb_strtoupper((string) $type);     // 'EQUIPO DE SEGURIDAD'

        return $this->state(fn () => ['product_type' => $value]);
    }

    /**
     * Producto sin stock.
     */
    public function outOfStock(): static
    {
        return $this->state(fn () => ['stock' => 0]);
    }

    /**
     * Servicio sin inventario (Honorarios, instalación, mantenimiento).
     *
     * - product_type: tipo custom 'HONORARIO' (no enum).
     * - is_service: true (controla descuento de stock, edición de precio en POS,
     *   exclusión de reportes de stock bajo).
     * - tax_type: Exento (servicios profesionales en HN son exentos).
     * - stock virtual de 999999 para que el SaleInventoryProcessor no se queje.
     */
    public function service(string $type = 'HONORARIO'): static
    {
        return $this->state(fn () => [
            'product_type' => mb_strtoupper($type),
            'is_service' => true,
            'tax_type' => TaxType::Exento,
            'condition' => ProductCondition::New,
            'stock' => 999999,
            'min_stock' => 0,
        ]);
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

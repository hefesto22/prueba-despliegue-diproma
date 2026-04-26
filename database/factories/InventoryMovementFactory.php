<?php

namespace Database\Factories;

use App\Enums\MovementType;
use App\Models\Establishment;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    protected $model = InventoryMovement::class;

    public function definition(): array
    {
        $stockBefore = fake()->numberBetween(0, 100);
        $quantity = fake()->numberBetween(1, 20);
        $type = fake()->randomElement(MovementType::cases());

        return [
            // Reutiliza la matriz existente (evita pollution del cache de CompanySetting).
            'establishment_id' => fn () => Establishment::main()->value('id')
                ?? Establishment::factory()->main()->create()->id,
            'product_id' => Product::factory(),
            'type' => $type,
            'quantity' => $quantity,
            'stock_before' => $stockBefore,
            'stock_after' => $type->isEntry()
                ? $stockBefore + $quantity
                : max(0, $stockBefore - $quantity),
            'notes' => fake()->optional(0.5)->sentence(),
        ];
    }

    /**
     * Movimiento en una sucursal específica — útil para tests de kardex
     * segregado por establecimiento.
     */
    public function forEstablishment(Establishment $establishment): static
    {
        return $this->state(fn () => ['establishment_id' => $establishment->id]);
    }

    /**
     * Movimiento de entrada por compra.
     */
    public function entradaCompra(): static
    {
        return $this->state(function (array $attributes) {
            $stockBefore = $attributes['stock_before'];
            $quantity = $attributes['quantity'];

            return [
                'type' => MovementType::EntradaCompra,
                'stock_after' => $stockBefore + $quantity,
            ];
        });
    }

    /**
     * Movimiento de salida por anulación.
     */
    public function salidaAnulacion(): static
    {
        return $this->state(function (array $attributes) {
            $stockBefore = $attributes['stock_before'];
            $quantity = $attributes['quantity'];

            return [
                'type' => MovementType::SalidaAnulacionCompra,
                'stock_after' => max(0, $stockBefore - $quantity),
            ];
        });
    }

    /**
     * Ajuste manual de entrada.
     */
    public function ajusteEntrada(): static
    {
        return $this->state(function (array $attributes) {
            $stockBefore = $attributes['stock_before'];
            $quantity = $attributes['quantity'];

            return [
                'type' => MovementType::AjusteEntrada,
                'stock_after' => $stockBefore + $quantity,
                'notes' => 'Ajuste por conteo físico',
            ];
        });
    }

    /**
     * Ajuste manual de salida.
     */
    public function ajusteSalida(): static
    {
        return $this->state(function (array $attributes) {
            $stockBefore = $attributes['stock_before'];
            $quantity = $attributes['quantity'];

            return [
                'type' => MovementType::AjusteSalida,
                'stock_after' => max(0, $stockBefore - $quantity),
                'notes' => 'Ajuste por merma/daño',
            ];
        });
    }

    /**
     * Para un producto específico.
     */
    public function forProduct(Product $product): static
    {
        return $this->state(fn () => [
            'product_id' => $product->id,
            'stock_before' => $product->stock,
        ]);
    }
}

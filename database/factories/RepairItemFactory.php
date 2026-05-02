<?php

namespace Database\Factories;

use App\Enums\RepairItemCondition;
use App\Enums\RepairItemSource;
use App\Enums\TaxType;
use App\Models\Repair;
use App\Models\RepairItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepairItem>
 */
class RepairItemFactory extends Factory
{
    protected $model = RepairItem::class;

    public function definition(): array
    {
        // Por defecto: línea de honorarios (exenta).
        $unitPrice = fake()->randomFloat(2, 200, 1500);
        $quantity = 1.00;

        return [
            'repair_id' => Repair::factory(),
            'source' => RepairItemSource::HonorariosReparacion,
            'product_id' => null,
            'condition' => null,
            'description' => 'Honorarios por reparación',
            'external_supplier' => null,
            'quantity' => $quantity,
            'unit_cost' => null,
            'unit_price' => $unitPrice,
            'tax_type' => TaxType::Exento,
            'subtotal' => round($unitPrice * $quantity, 2),
            'isv_amount' => 0.00,
            'total' => round($unitPrice * $quantity, 2),
        ];
    }

    /**
     * Pieza externa nueva (gravada 15%).
     * El unit_price ya incluye ISV (consistente con SaleItem).
     */
    public function externaNueva(): static
    {
        $unitPrice = fake()->randomFloat(2, 100, 5000);
        $quantity = fake()->numberBetween(1, 3);
        $multiplier = (float) config('tax.multiplier', 1.15);
        $base = round($unitPrice / $multiplier, 2);
        $isv = round($unitPrice - $base, 2);

        return $this->state(fn () => [
            'source' => RepairItemSource::PiezaExterna,
            'condition' => RepairItemCondition::Nueva,
            'description' => 'Pieza nueva externa',
            'external_supplier' => fake()->company(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_type' => TaxType::Gravado15,
            'subtotal' => round($base * $quantity, 2),
            'isv_amount' => round($isv * $quantity, 2),
            'total' => round($unitPrice * $quantity, 2),
        ]);
    }

    /**
     * Pieza externa usada (exenta).
     */
    public function externaUsada(): static
    {
        $unitPrice = fake()->randomFloat(2, 50, 1500);
        $quantity = fake()->numberBetween(1, 3);

        return $this->state(fn () => [
            'source' => RepairItemSource::PiezaExterna,
            'condition' => RepairItemCondition::Usada,
            'description' => 'Pieza usada',
            'external_supplier' => fake()->company(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_type' => TaxType::Exento,
            'subtotal' => round($unitPrice * $quantity, 2),
            'isv_amount' => 0.00,
            'total' => round($unitPrice * $quantity, 2),
        ]);
    }

    public function honorariosMantenimiento(): static
    {
        return $this->state(fn () => [
            'source' => RepairItemSource::HonorariosMantenimiento,
            'description' => 'Honorarios por mantenimiento preventivo',
        ]);
    }
}

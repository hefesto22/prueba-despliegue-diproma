<?php

namespace Database\Factories;

use App\Models\SpecOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SpecOption>
 */
class SpecOptionFactory extends Factory
{
    protected $model = SpecOption::class;

    public function definition(): array
    {
        return [
            'field_key' => fake()->randomElement(['processor', 'ram', 'storage', 'screen', 'gpu', 'os']),
            'value' => strtoupper(fake()->word()),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    /**
     * Opción para un campo específico.
     */
    public function forField(string $fieldKey, string $value): static
    {
        return $this->state(fn () => [
            'field_key' => $fieldKey,
            'value' => strtoupper($value),
        ]);
    }

    /**
     * Opción inactiva.
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}

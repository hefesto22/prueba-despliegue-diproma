<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'rtn' => fake()->unique()->numerify('####-####-######'),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
        ];
    }

    /**
     * Consumidor final (sin RTN).
     */
    public function consumidorFinal(): static
    {
        return $this->state(fn () => [
            'rtn' => null,
            'email' => null,
        ]);
    }

    /**
     * Cliente inactivo.
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}

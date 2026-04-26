<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'rtn' => fake()->unique()->numerify('##############'),  // 14 dígitos exactos
            'company_name' => fake()->optional(0.3)->company(),
            'contact_name' => fake()->name(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->numerify('+504 ####-####'),
            'phone_secondary' => fake()->optional(0.3)->numerify('+504 ####-####'),
            'address' => fake()->address(),
            'city' => fake()->randomElement([
                'Tegucigalpa', 'San Pedro Sula', 'La Ceiba', 'Comayagua',
                'Choluteca', 'Puerto Cortés', 'El Progreso', 'Siguatepeque',
            ]),
            'department' => fake()->randomElement([
                'Francisco Morazán', 'Cortés', 'Atlántida', 'Comayagua',
                'Choluteca', 'Yoro', 'Olancho', 'Santa Bárbara',
            ]),
            'credit_days' => fake()->randomElement([0, 0, 0, 15, 30, 45, 60]),
            'notes' => fake()->optional(0.2)->sentence(),
            'is_active' => true,
        ];
    }

    /**
     * Proveedor inactivo.
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Proveedor con crédito.
     */
    public function withCredit(int $days = 30): static
    {
        return $this->state(fn () => ['credit_days' => $days]);
    }

    /**
     * Proveedor de contado (sin crédito).
     */
    public function cash(): static
    {
        return $this->state(fn () => ['credit_days' => 0]);
    }
}

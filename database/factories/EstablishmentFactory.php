<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use App\Models\Establishment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Establishment>
 */
class EstablishmentFactory extends Factory
{
    protected $model = Establishment::class;

    public function definition(): array
    {
        return [
            // Reusa el CompanySetting existente si ya hay uno (single-tenant).
            // Evita crear un segundo CompanySetting que contamine el cache
            // del test (CompanySetting::current() cachea la primera instancia).
            'company_setting_id' => fn () => CompanySetting::query()->value('id')
                ?? CompanySetting::factory()->create()->id,
            'code' => str_pad((string) $this->faker->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'emission_point' => str_pad((string) $this->faker->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'name' => $this->faker->company(),
            'type' => 'fijo',
            'address' => $this->faker->address(),
            'city' => $this->faker->city(),
            'department' => 'Cortés',
            'municipality' => 'San Pedro Sula',
            'phone' => $this->faker->phoneNumber(),
            'is_main' => false,
            'is_active' => true,
        ];
    }

    public function main(): static
    {
        return $this->state(fn () => [
            'is_main' => true,
            'code' => '001',
            'emission_point' => '001',
            'name' => 'Matriz',
        ]);
    }

    public function movil(): static
    {
        return $this->state(fn () => ['type' => 'movil']);
    }
}

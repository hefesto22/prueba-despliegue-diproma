<?php

namespace Database\Factories;

use App\Models\CaiRange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CaiRange>
 */
class CaiRangeFactory extends Factory
{
    protected $model = CaiRange::class;

    public function definition(): array
    {
        $prefix = '001-001-01';

        return [
            'cai' => strtoupper($this->faker->regexify('[A-F0-9]{6}-[A-F0-9]{6}-[A-F0-9]{6}-[A-F0-9]{6}-[A-F0-9]{6}-[A-F0-9]{2}')),
            'authorization_date' => now()->subMonth()->toDateString(),
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'document_type' => '01',
            'establishment_id' => null,
            'prefix' => $prefix,
            'range_start' => 1,
            'range_end' => 500,
            'current_number' => 0,
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expiration_date' => now()->subDay()->toDateString(),
        ]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (array $attrs) => [
            'current_number' => $attrs['range_end'] ?? 500,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Tithe;
use App\Models\TitheDeduction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TitheDeduction>
 */
class TitheDeductionFactory extends Factory
{
    protected $model = TitheDeduction::class;

    public function definition(): array
    {
        return [
            'tithe_id' => Tithe::factory(),
            'concept' => 'Pago de empleados',
            'amount' => 200,
        ];
    }
}

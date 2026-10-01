<?php

namespace Database\Factories;

use App\Models\Tithe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tithe>
 *
 * Diezmo de 1,000 de utilidad sin pagos agregados (diezmo 100).
 */
class TitheFactory extends Factory
{
    protected $model = Tithe::class;

    public function definition(): array
    {
        return [
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'revenue' => 3000,
            'cost' => 1500,
            'expense_purchases' => 400,
            'card_fees' => 100,
            'net_profit' => 1000,
            'extra_deductions' => 0,
            'base_amount' => 1000,
            'rate' => 0.10,
            'amount' => 100,
        ];
    }

    public function forMonth(int $year, int $month): static
    {
        return $this->state(fn () => ['year' => $year, 'month' => $month]);
    }
}

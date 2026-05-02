<?php

namespace Database\Factories;

use App\Enums\CustomerCreditSource;
use App\Models\Customer;
use App\Models\CustomerCredit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerCredit>
 */
class CustomerCreditFactory extends Factory
{
    protected $model = CustomerCredit::class;

    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 100, 2500);

        return [
            'customer_id' => Customer::factory(),
            'source_type' => CustomerCreditSource::RepairAdvance,
            'source_repair_id' => null,
            'establishment_id' => null,
            'amount' => $amount,
            'balance' => $amount,
            'expires_at' => null,
            'fully_used_at' => null,
            'description' => 'Anticipo de reparación convertido a crédito',
        ];
    }

    public function partiallyUsed(float $usedAmount): static
    {
        return $this->state(function (array $attrs) use ($usedAmount) {
            $newBalance = max(0, (float) $attrs['amount'] - $usedAmount);
            return [
                'balance' => $newBalance,
                'fully_used_at' => $newBalance == 0 ? now() : null,
            ];
        });
    }

    public function fullyUsed(): static
    {
        return $this->state(fn (array $attrs) => [
            'balance' => 0,
            'fully_used_at' => now(),
        ]);
    }
}

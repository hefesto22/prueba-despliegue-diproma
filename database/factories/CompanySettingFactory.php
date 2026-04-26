<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySetting>
 */
class CompanySettingFactory extends Factory
{
    protected $model = CompanySetting::class;

    public function definition(): array
    {
        return [
            'legal_name' => $this->faker->company(),
            'trade_name' => $this->faker->company(),
            'rtn' => str_pad((string) $this->faker->numberBetween(10000000000000, 99999999999999), 14, '0', STR_PAD_LEFT),
            'business_type' => $this->faker->word(),
            'address' => $this->faker->address(),
            'city' => $this->faker->city(),
            'department' => 'Cortés',
            'municipality' => 'San Pedro Sula',
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->companyEmail(),
            'tax_regime' => 'normal',
        ];
    }
}

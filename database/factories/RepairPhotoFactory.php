<?php

namespace Database\Factories;

use App\Enums\RepairPhotoPurpose;
use App\Models\Repair;
use App\Models\RepairPhoto;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepairPhoto>
 */
class RepairPhotoFactory extends Factory
{
    protected $model = RepairPhoto::class;

    public function definition(): array
    {
        return [
            'repair_id' => Repair::factory(),
            'photo_path' => 'repairs/test/' . fake()->uuid() . '.jpg',
            'purpose' => RepairPhotoPurpose::Recepcion,
            'caption' => fake()->optional()->sentence(4),
            'file_size' => fake()->numberBetween(50_000, 2_500_000),
            'uploaded_by' => User::factory(),
        ];
    }

    public function recepcion(): static
    {
        return $this->state(fn () => ['purpose' => RepairPhotoPurpose::Recepcion]);
    }

    public function finalizada(): static
    {
        return $this->state(fn () => ['purpose' => RepairPhotoPurpose::Finalizada]);
    }
}

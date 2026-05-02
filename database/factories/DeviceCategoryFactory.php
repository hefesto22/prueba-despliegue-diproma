<?php

namespace Database\Factories;

use App\Models\DeviceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceCategory>
 */
class DeviceCategoryFactory extends Factory
{
    protected $model = DeviceCategory::class;

    public function definition(): array
    {
        // Sufijo único en `name` para evitar colisión con la constraint UNIQUE
        // en `device_categories.name`. Sin esto, factory()->count(N) explotaría
        // al segundo registro porque los nombres del randomElement son fijos.
        // El sufijo solo aparece en factories (tests/seeders), nunca en datos
        // reales — el DeviceCategorySeeder usa nombres limpios sin sufijo.
        $base = fake()->randomElement([
            'Laptop', 'Desktop', 'Tablet', 'Consola', 'Teléfono',
            'Impresora', 'Monitor', 'Componente',
        ]);
        $name = $base . ' Test ' . fake()->unique()->numerify('###');

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'icon' => fake()->randomElement([
                'heroicon-o-computer-desktop',
                'heroicon-o-device-tablet',
                'heroicon-o-device-phone-mobile',
                'heroicon-o-printer',
            ]),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}

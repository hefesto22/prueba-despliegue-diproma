<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Laptops', 'Desktops', 'Tablets', 'Monitores', 'Impresoras',
            'Componentes', 'Accesorios', 'Consolas', 'Redes', 'Almacenamiento',
            'Audio', 'Periféricos', 'Software', 'Servidores', 'Seguridad',
        ]);

        return [
            'name' => $name,
            // slug se autogenera en el boot del modelo, no setearlo aquí
            'description' => fake()->optional(0.5)->sentence(),
            'parent_id' => null,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * Categoría inactiva.
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Subcategoría (requiere padre existente).
     */
    public function childOf(Category $parent): static
    {
        return $this->state(fn () => ['parent_id' => $parent->id]);
    }
}

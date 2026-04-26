<?php

namespace Tests\Feature\Models;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_category_with_factory(): void
    {
        $category = Category::factory()->create();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => $category->name,
        ]);
    }

    public function test_slug_is_auto_generated_on_create(): void
    {
        $category = Category::factory()->create(['name' => 'Laptops Gaming']);

        $this->assertEquals('laptops-gaming', $category->slug);
    }

    public function test_parent_child_relationship_works(): void
    {
        $parent = Category::factory()->create(['name' => 'Computadoras']);
        $child = Category::factory()->childOf($parent)->create(['name' => 'Laptops']);

        $this->assertEquals($parent->id, $child->parent_id);
        $this->assertTrue($child->parent->is($parent));
        $this->assertCount(1, $parent->children);
    }

    public function test_full_name_attribute_shows_hierarchy(): void
    {
        $parent = Category::factory()->create(['name' => 'Computadoras']);
        $child = Category::factory()->childOf($parent)->create(['name' => 'Laptops']);

        $this->assertEquals('Computadoras > Laptops', $child->full_name);
    }

    public function test_active_scope_filters_correctly(): void
    {
        Category::factory()->count(3)->create(['is_active' => true]);
        Category::factory()->count(2)->inactive()->create();

        $this->assertCount(3, Category::active()->get());
    }

    public function test_root_scope_returns_only_top_level(): void
    {
        $parent = Category::factory()->create();
        Category::factory()->childOf($parent)->create();

        $this->assertCount(1, Category::root()->get());
    }
}

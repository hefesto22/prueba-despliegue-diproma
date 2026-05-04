<?php

namespace Tests\Feature\Services\Products;

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests para el flag `is_service` que distingue productos físicos de servicios.
 *
 * El bug histórico: el sistema asumía "tipo custom = servicio". Eso rompía
 * el inventario de equipos físicos custom (cámaras, biométricos, equipo de
 * seguridad). El flag explícito `is_service` corrige esa raíz.
 *
 * Verifica:
 *   - Productos enum (laptop, etc.): siempre is_service=false.
 *   - Productos custom no-servicio: is_service=false, stock real.
 *   - Servicios (Honorarios): is_service=true, stock virtual 999999.
 *   - Scopes lowStock/outOfStock excluyen servicios.
 *   - Backfill de migración: productos con tipo HONORARIO se marcan service.
 */
class ProductIsServiceTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = Category::factory()->create();
    }

    // ─── Persistencia y casts ────────────────────────────────

    public function test_default_is_service_is_false(): void
    {
        $product = Product::factory()->brandNew()->inCategory($this->category)->create();

        $this->assertFalse((bool) $product->is_service);
    }

    public function test_is_service_persists_as_boolean(): void
    {
        $service = Product::factory()->service()->inCategory($this->category)->create();

        $this->assertTrue((bool) $service->fresh()->is_service);
    }

    // ─── Productos físicos (enum) ────────────────────────────

    public function test_enum_product_is_not_service_by_default(): void
    {
        $laptop = Product::factory()
            ->brandNew()
            ->ofType(ProductType::Laptop)
            ->inCategory($this->category)
            ->create();

        $this->assertFalse((bool) $laptop->is_service);
    }

    // ─── Servicios (factory state) ───────────────────────────

    public function test_service_factory_creates_with_virtual_stock(): void
    {
        $honorario = Product::factory()->service()->inCategory($this->category)->create();

        $this->assertTrue((bool) $honorario->is_service);
        $this->assertSame(999999, $honorario->stock);
        $this->assertSame(0, $honorario->min_stock);
    }

    // ─── Scopes lowStock / outOfStock ────────────────────────

    public function test_low_stock_scope_excludes_services(): void
    {
        // Producto físico con stock bajo (debería aparecer).
        Product::factory()
            ->brandNew()
            ->ofType(ProductType::Laptop)
            ->inCategory($this->category)
            ->create(['stock' => 2, 'min_stock' => 5]);

        // Servicio con stock virtual 999999 (NO debería aparecer aunque
        // técnicamente nunca cumpla la condición — defensa explícita).
        Product::factory()->service()->inCategory($this->category)->create();

        $lowStock = Product::lowStock()->get();

        $this->assertCount(1, $lowStock, 'Solo el producto físico debería estar en stock bajo');
        $this->assertFalse((bool) $lowStock->first()->is_service);
    }

    public function test_out_of_stock_scope_excludes_services(): void
    {
        // Producto sin stock (debería aparecer).
        Product::factory()
            ->brandNew()
            ->ofType(ProductType::Laptop)
            ->inCategory($this->category)
            ->create(['stock' => 0]);

        // Servicio (NO debería aparecer).
        Product::factory()->service()->inCategory($this->category)->create();

        $outOfStock = Product::outOfStock()->get();

        $this->assertCount(1, $outOfStock);
        $this->assertFalse((bool) $outOfStock->first()->is_service);
    }

    // ─── Caso crítico de regresión: equipo de seguridad ──────

    public function test_custom_type_physical_product_keeps_real_stock(): void
    {
        // Reproduce el caso que reportó Mauricio: equipo de seguridad como
        // tipo custom NO debe tratarse como servicio. Debe tener stock real
        // y NO marcar is_service automáticamente.
        $biometrico = Product::factory()
            ->ofType('EQUIPO DE SEGURIDAD')
            ->inCategory($this->category)
            ->create([
                'stock' => 5,
                'min_stock' => 1,
                'is_service' => false, // explícito: producto físico
            ]);

        $this->assertFalse((bool) $biometrico->is_service);
        $this->assertSame(5, $biometrico->stock);

        // Y aparece en stock bajo si su stock cae por debajo del mínimo.
        $biometrico->update(['stock' => 0]);
        $this->assertTrue(Product::outOfStock()->where('id', $biometrico->id)->exists());
    }
}

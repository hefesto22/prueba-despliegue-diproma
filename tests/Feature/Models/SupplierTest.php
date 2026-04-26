<?php

namespace Tests\Feature\Models;

use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_supplier_with_factory(): void
    {
        $supplier = Supplier::factory()->create();

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => $supplier->name,
        ]);
    }

    public function test_rtn_is_formatted_correctly(): void
    {
        $supplier = Supplier::factory()->create(['rtn' => '08011999123456']);

        $this->assertEquals('0801-1999-123456', $supplier->formatted_rtn);
    }

    public function test_display_name_shows_company_name_when_different(): void
    {
        $supplier = Supplier::factory()->create([
            'name' => 'TecnoHN',
            'company_name' => 'Tecnología Honduras S.A.',
        ]);

        $this->assertEquals('TecnoHN (Tecnología Honduras S.A.)', $supplier->display_name);
    }

    public function test_display_name_shows_only_name_when_company_is_same(): void
    {
        $supplier = Supplier::factory()->create([
            'name' => 'TecnoHN',
            'company_name' => 'TecnoHN',
        ]);

        $this->assertEquals('TecnoHN', $supplier->display_name);
    }

    public function test_has_credit_returns_true_when_credit_days_greater_than_zero(): void
    {
        $supplier = Supplier::factory()->withCredit(30)->create();

        $this->assertTrue($supplier->hasCredit());
    }

    public function test_has_credit_returns_false_for_cash_supplier(): void
    {
        $supplier = Supplier::factory()->cash()->create();

        $this->assertFalse($supplier->hasCredit());
    }

    public function test_active_scope_filters_correctly(): void
    {
        // Baseline: la migración RI sembra el proveedor genérico "Varios / Sin
        // identificar" como is_active=true. El scope active() debe contarlo,
        // así que medimos delta en vez de absoluto — el invariante real es
        // "active() incluye exactamente los que tienen is_active=true".
        $baselineActive = Supplier::active()->count();

        Supplier::factory()->count(3)->create(['is_active' => true]);
        Supplier::factory()->count(2)->inactive()->create();

        $this->assertCount($baselineActive + 3, Supplier::active()->get());
    }

    public function test_with_credit_scope_filters_correctly(): void
    {
        Supplier::factory()->count(2)->withCredit(30)->create();
        Supplier::factory()->count(3)->cash()->create();

        $this->assertCount(2, Supplier::withCredit()->get());
    }

    public function test_soft_delete_works(): void
    {
        // Baseline: el genérico de RI ya existe por seed de migración y NO se
        // soft-deletea — sigue apareciendo en all(). Lo único que nos importa
        // verificar es que el supplier que CREAMOS y borramos desaparece de
        // las queries normales pero sigue en withTrashed().
        $baselineTotal = Supplier::count();

        $supplier = Supplier::factory()->create();
        $supplier->delete();

        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
        $this->assertCount($baselineTotal, Supplier::all());
        $this->assertCount($baselineTotal + 1, Supplier::withTrashed()->get());
    }
}

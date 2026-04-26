<?php

namespace Tests\Feature\Models;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_customer_with_factory(): void
    {
        $customer = Customer::factory()->create();

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'is_active' => true,
        ]);
    }

    public function test_consumidor_final_has_no_rtn(): void
    {
        $customer = Customer::factory()->consumidorFinal()->create();

        $this->assertNull($customer->rtn);
        $this->assertTrue($customer->isConsumidorFinal());
    }

    public function test_customer_with_rtn_is_not_consumidor_final(): void
    {
        $customer = Customer::factory()->create(['rtn' => '0801-1999-123456']);

        $this->assertFalse($customer->isConsumidorFinal());
    }

    public function test_rtn_formatted_correctly(): void
    {
        $customer = Customer::factory()->create(['rtn' => '08011999123456']);

        $this->assertEquals('0801-1999-123456', $customer->formatted_rtn);
    }

    public function test_active_scope_filters_correctly(): void
    {
        Customer::factory()->count(3)->create(['is_active' => true]);
        Customer::factory()->count(2)->create(['is_active' => false]);

        $this->assertEquals(3, Customer::active()->count());
    }

    public function test_with_rtn_scope_filters_correctly(): void
    {
        Customer::factory()->count(2)->create();  // con RTN
        Customer::factory()->consumidorFinal()->count(3)->create();

        $this->assertEquals(2, Customer::withRtn()->count());
    }

    public function test_soft_delete_works(): void
    {
        $customer = Customer::factory()->create();

        $customer->delete();

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->assertEquals(0, Customer::count());
        $this->assertEquals(1, Customer::withTrashed()->count());
    }

    public function test_rtn_is_unique(): void
    {
        Customer::factory()->create(['rtn' => '0801-2000-000001']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Customer::factory()->create(['rtn' => '0801-2000-000001']);
    }
}

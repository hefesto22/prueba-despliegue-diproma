<?php

namespace Tests\Feature\Models;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_number_is_auto_generated(): void
    {
        $purchase = Purchase::factory()->create(['date' => now()]);

        $this->assertStringStartsWith('COMP-' . now()->year . '-', $purchase->purchase_number);
    }

    public function test_purchase_numbers_are_sequential(): void
    {
        $p1 = Purchase::factory()->create(['date' => now()]);
        $p2 = Purchase::factory()->create(['date' => now()]);

        $num1 = (int) substr($p1->purchase_number, -5);
        $num2 = (int) substr($p2->purchase_number, -5);

        $this->assertEquals($num1 + 1, $num2);
    }

    public function test_due_date_calculated_from_credit_days(): void
    {
        $supplier = Supplier::factory()->withCredit(30)->create();

        $purchase = Purchase::factory()->create([
            'supplier_id' => $supplier->id,
            'date' => '2026-04-01',
            'credit_days' => 30,
        ]);

        $this->assertEquals('2026-05-01', $purchase->due_date->format('Y-m-d'));
    }

    public function test_cash_purchase_has_no_due_date(): void
    {
        $purchase = Purchase::factory()->create([
            'date' => now(),
            'credit_days' => 0,
        ]);

        $this->assertNull($purchase->due_date);
    }

    public function test_is_overdue_returns_true_when_past_due(): void
    {
        $purchase = Purchase::factory()->create([
            'date' => now()->subDays(40),
            'due_date' => now()->subDays(10),
            'credit_days' => 30,
            'status' => PurchaseStatus::Confirmada,
            'payment_status' => PaymentStatus::Pendiente,
        ]);

        $this->assertTrue($purchase->isOverdue());
    }

    public function test_is_overdue_returns_false_when_paid(): void
    {
        $purchase = Purchase::factory()->create([
            'date' => now()->subDays(40),
            'due_date' => now()->subDays(10),
            'credit_days' => 30,
            'status' => PurchaseStatus::Confirmada,
            'payment_status' => PaymentStatus::Pagada,
        ]);

        $this->assertFalse($purchase->isOverdue());
    }

    public function test_supplier_relationship(): void
    {
        $supplier = Supplier::factory()->create();
        $purchase = Purchase::factory()->fromSupplier($supplier)->create(['date' => now()]);

        $this->assertTrue($purchase->supplier->is($supplier));
        $this->assertCount(1, $supplier->purchases);
    }

    public function test_borradores_scope(): void
    {
        Purchase::factory()->count(2)->create(['date' => now(), 'status' => PurchaseStatus::Borrador]);
        Purchase::factory()->create(['date' => now(), 'status' => PurchaseStatus::Confirmada]);

        $this->assertCount(2, Purchase::borradores()->get());
    }

    public function test_soft_delete_works(): void
    {
        $purchase = Purchase::factory()->create(['date' => now()]);

        $purchase->delete();

        $this->assertSoftDeleted('purchases', ['id' => $purchase->id]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\RepairStatus;
use App\Models\Customer;
use App\Models\DeviceCategory;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Repair>
 */
class RepairFactory extends Factory
{
    protected $model = Repair::class;

    public function definition(): array
    {
        $customer = Customer::factory()->create();

        return [
            'qr_token' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_rtn' => $customer->rtn,
            'device_category_id' => DeviceCategory::factory(),
            'device_brand' => fake()->randomElement(['HP', 'Dell', 'Lenovo', 'Apple', 'Sony', 'Nintendo']),
            'device_model' => fake()->bothify('Model-####'),
            'device_serial' => fake()->bothify('SN-####-????'),
            'reported_issue' => fake()->sentence(10),
            'status' => RepairStatus::Recibido,
            'received_at' => now(),
            'subtotal' => 0,
            'exempt_total' => 0,
            'taxable_total' => 0,
            'isv' => 0,
            'total' => 0,
            'advance_payment' => 0,
        ];
    }

    public function quoted(): static
    {
        return $this->state(fn () => [
            'status' => RepairStatus::Cotizado,
            'quoted_at' => now(),
            'diagnosis' => fake()->sentence(15),
        ]);
    }

    public function approved(): static
    {
        return $this->quoted()->state(fn () => [
            'status' => RepairStatus::Aprobado,
            'approved_at' => now(),
        ]);
    }

    public function inRepair(): static
    {
        return $this->approved()->state(fn () => [
            'status' => RepairStatus::EnReparacion,
            'repair_started_at' => now(),
            'technician_id' => User::factory(),
        ]);
    }

    public function readyForDelivery(): static
    {
        return $this->inRepair()->state(fn () => [
            'status' => RepairStatus::ListoEntrega,
            'completed_at' => now(),
        ]);
    }

    public function delivered(): static
    {
        return $this->readyForDelivery()->state(fn () => [
            'status' => RepairStatus::Entregada,
            'delivered_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->quoted()->state(fn () => [
            'status' => RepairStatus::Rechazada,
            'rejected_at' => now(),
        ]);
    }

    public function withAdvance(float $amount = 500.00): static
    {
        return $this->state(fn () => [
            'advance_payment' => $amount,
        ]);
    }

    public function consumidorFinal(): static
    {
        return $this->state(fn () => [
            'customer_id' => null,
            'customer_name' => 'Consumidor Final',
            'customer_rtn' => null,
        ]);
    }
}

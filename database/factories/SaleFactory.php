<?php

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        $customer = Customer::factory()->create();

        return [
            // Reutiliza la matriz existente (evita pollution del cache de CompanySetting).
            // Si el test aún no ha creado una, se crea on-demand.
            'establishment_id' => fn () => Establishment::main()->value('id')
                ?? Establishment::factory()->main()->create()->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_rtn' => $customer->rtn,
            'date' => now(),
            'status' => SaleStatus::Pendiente,
            'subtotal' => 0,
            'isv' => 0,
            'total' => 0,
        ];
    }

    /**
     * Venta en una sucursal específica — útil para tests multi-sucursal.
     */
    public function forEstablishment(Establishment $establishment): static
    {
        return $this->state(fn () => ['establishment_id' => $establishment->id]);
    }

    /**
     * Venta completada.
     */
    public function completada(): static
    {
        return $this->state(fn () => ['status' => SaleStatus::Completada]);
    }

    /**
     * Venta anulada.
     */
    public function anulada(): static
    {
        return $this->state(fn () => ['status' => SaleStatus::Anulada]);
    }

    /**
     * Venta a consumidor final (sin RTN, sin customer_id).
     */
    public function consumidorFinal(): static
    {
        return $this->state(fn () => [
            'customer_id' => null,
            'customer_name' => 'Consumidor Final',
            'customer_rtn' => null,
        ]);
    }

    /**
     * Venta con descuento porcentual.
     */
    public function withDiscountPercentage(float $value = 10): static
    {
        return $this->state(fn () => [
            'discount_type' => 'percentage',
            'discount_value' => $value,
        ]);
    }

    /**
     * Venta con descuento fijo.
     */
    public function withDiscountFixed(float $value = 100): static
    {
        return $this->state(fn () => [
            'discount_type' => 'fixed',
            'discount_value' => $value,
        ]);
    }
}

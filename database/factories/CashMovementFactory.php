<?php

namespace Database\Factories;

use App\Enums\CashMovementType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CashMovement>
 */
class CashMovementFactory extends Factory
{
    protected $model = CashMovement::class;

    public function definition(): array
    {
        return [
            'cash_session_id' => fn () => CashSession::factory()->create()->id,
            'user_id' => fn () => User::factory()->create()->id,
            'type' => CashMovementType::SaleIncome,
            'payment_method' => PaymentMethod::Efectivo,
            'amount' => 100.00,
            'category' => null,
            'description' => null,
            'reference_type' => null,
            'reference_id' => null,
            'occurred_at' => now(),
        ];
    }

    public function forSession(CashSession $session): static
    {
        return $this->state(fn () => ['cash_session_id' => $session->id]);
    }

    public function type(CashMovementType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function paymentMethod(PaymentMethod $method): static
    {
        return $this->state(fn () => ['payment_method' => $method]);
    }

    public function amount(float $amount): static
    {
        return $this->state(fn () => ['amount' => $amount]);
    }

    /**
     * Gasto con categoría — cubre el caso común (type=expense + category requerida).
     */
    public function expense(float $amount = 100.00, ExpenseCategory $category = ExpenseCategory::Otros): static
    {
        return $this->state(fn () => [
            'type' => CashMovementType::Expense,
            'payment_method' => PaymentMethod::Efectivo,
            'amount' => $amount,
            'category' => $category,
        ]);
    }

    /**
     * Ingreso por venta — modela el flujo típico del POS.
     */
    public function saleIncome(float $amount = 500.00, PaymentMethod $method = PaymentMethod::Efectivo): static
    {
        return $this->state(fn () => [
            'type' => CashMovementType::SaleIncome,
            'payment_method' => $method,
            'amount' => $amount,
        ]);
    }

    /**
     * Depósito bancario (saca efectivo de caja).
     */
    public function deposit(float $amount = 5000.00): static
    {
        return $this->state(fn () => [
            'type' => CashMovementType::Deposit,
            'payment_method' => PaymentMethod::Efectivo,
            'amount' => $amount,
        ]);
    }
}

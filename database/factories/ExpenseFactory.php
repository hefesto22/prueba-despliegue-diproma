<?php

namespace Database\Factories;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\Establishment;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        $amountTotal = $this->faker->randomFloat(2, 50, 5000);

        return [
            'establishment_id'   => Establishment::factory(),
            'user_id'            => User::factory(),
            'expense_date'       => now()->toDateString(),
            'category'           => ExpenseCategory::Otros->value,
            'payment_method'     => PaymentMethod::Efectivo->value,
            'amount_total'       => $amountTotal,
            'isv_amount'         => null,
            'is_isv_deductible'  => false,
            'description'        => $this->faker->sentence(6),

            // Sin proveedor por default — el caller que necesite gasto con
            // factura usa el state `withProvider()`.
            'provider_name'           => null,
            'provider_rtn'            => null,
            'provider_invoice_number' => null,
            'provider_invoice_cai'    => null,
            'provider_invoice_date'   => null,
            'attachment_path'         => null,
        ];
    }

    /**
     * Gasto con factura del proveedor (datos fiscales completos).
     *
     * Calcula isv_amount = 15% sobre la base extraida de amount_total para
     * que los totales sean internamente consistentes en tests fiscales.
     */
    public function withProvider(?string $rtn = null, ?string $name = null): static
    {
        return $this->state(function (array $attrs) use ($rtn, $name) {
            $total = (float) $attrs['amount_total'];
            // Asume gravado 15%: total = base + base*0.15 → base = total/1.15
            $base = round($total / 1.15, 2);
            $isv  = round($total - $base, 2);

            return [
                'provider_name'           => $name ?? $this->faker->company(),
                'provider_rtn'            => $rtn ?? $this->faker->numerify('0801##########'),
                'provider_invoice_number' => '000-001-01-' . $this->faker->numerify('########'),
                'provider_invoice_cai'    => strtoupper($this->faker->bothify('??????-??????-??????-??????-??????-##')),
                'provider_invoice_date'   => $attrs['expense_date'],
                'isv_amount'              => $isv,
                'is_isv_deductible'       => true,
            ];
        });
    }

    public function category(ExpenseCategory $category): static
    {
        return $this->state(fn () => ['category' => $category->value]);
    }

    public function paymentMethod(PaymentMethod $method): static
    {
        return $this->state(fn () => ['payment_method' => $method->value]);
    }

    public function deducible(): static
    {
        return $this->state(fn () => ['is_isv_deductible' => true]);
    }

    public function forMonth(int $year, int $month, int $day = 15): static
    {
        return $this->state(fn () => [
            'expense_date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
        ]);
    }
}

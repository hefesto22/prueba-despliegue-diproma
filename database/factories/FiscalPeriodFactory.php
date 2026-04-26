<?php

namespace Database\Factories;

use App\Models\FiscalPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalPeriod>
 */
class FiscalPeriodFactory extends Factory
{
    protected $model = FiscalPeriod::class;

    public function definition(): array
    {
        return [
            'period_year' => (int) now()->year,
            'period_month' => (int) now()->month,
            'declared_at' => null,
            'declared_by' => null,
            'declaration_notes' => null,
            'reopened_at' => null,
            'reopened_by' => null,
            'reopen_reason' => null,
        ];
    }

    /**
     * Período con año y mes específicos.
     */
    public function forMonth(int $year, int $month): static
    {
        return $this->state(fn () => [
            'period_year' => $year,
            'period_month' => $month,
        ]);
    }

    /**
     * Período declarado al SAR (cerrado).
     */
    public function declared(?User $user = null, ?string $notes = null): static
    {
        return $this->state(fn (array $attrs) => [
            'declared_at' => now()->subDays(rand(1, 25)),
            'declared_by' => $user?->id ?? User::factory(),
            'declaration_notes' => $notes ?? 'Declaración presentada al SAR según acuse automático',
        ]);
    }

    /**
     * Período reabierto (declaración rectificativa pendiente de re-declarar).
     * Aplica sobre un período previamente declarado.
     */
    public function reopened(?User $user = null, ?string $reason = null): static
    {
        return $this->declared()->state(fn (array $attrs) => [
            'reopened_at' => now(),
            'reopened_by' => $user?->id ?? User::factory(),
            'reopen_reason' => $reason ?? 'Declaración rectificativa solicitada por el contador',
        ]);
    }
}

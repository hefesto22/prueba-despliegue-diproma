<?php

namespace Database\Factories;

use App\Models\CashSession;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CashSession>
 */
class CashSessionFactory extends Factory
{
    protected $model = CashSession::class;

    public function definition(): array
    {
        return [
            // Reutiliza matriz existente para no contaminar cache de CompanySetting.
            'establishment_id' => fn () => Establishment::main()->value('id')
                ?? Establishment::factory()->main()->create()->id,
            'opened_by_user_id' => fn () => User::factory()->create()->id,
            'opened_at' => now(),
            'opening_amount' => 1000.00,
            // Por default: sesión abierta (closed_at = null).
            'closed_at' => null,
            'expected_closing_amount' => null,
            'actual_closing_amount' => null,
            'discrepancy' => null,
            'closed_by_user_id' => null,
            'authorized_by_user_id' => null,
        ];
    }

    /**
     * Sesión abierta en una sucursal específica.
     */
    public function forEstablishment(Establishment $establishment): static
    {
        return $this->state(fn () => ['establishment_id' => $establishment->id]);
    }

    /**
     * Sesión abierta por un usuario específico.
     */
    public function openedBy(User $user): static
    {
        return $this->state(fn () => ['opened_by_user_id' => $user->id]);
    }

    /**
     * Monto de apertura específico.
     */
    public function openingAmount(float $amount): static
    {
        return $this->state(fn () => ['opening_amount' => $amount]);
    }

    /**
     * Sesión cerrada con cuadre exacto (sin descuadre).
     */
    public function closed(?User $closedBy = null): static
    {
        return $this->state(function (array $attributes) use ($closedBy) {
            $expected = (float) ($attributes['opening_amount'] ?? 1000.00);

            return [
                'closed_at' => now()->addHours(8),
                'closed_by_user_id' => $closedBy?->id ?? User::factory()->create()->id,
                'expected_closing_amount' => $expected,
                'actual_closing_amount' => $expected,
                'discrepancy' => 0.00,
            ];
        });
    }

    /**
     * Sesión cerrada con descuadre (positivo = sobra, negativo = falta).
     */
    public function closedWithDiscrepancy(float $discrepancy, ?User $closedBy = null, ?User $authorizedBy = null): static
    {
        return $this->state(function (array $attributes) use ($discrepancy, $closedBy, $authorizedBy) {
            $expected = (float) ($attributes['opening_amount'] ?? 1000.00);

            return [
                'closed_at' => now()->addHours(8),
                'closed_by_user_id' => $closedBy?->id ?? User::factory()->create()->id,
                'expected_closing_amount' => $expected,
                'actual_closing_amount' => $expected + $discrepancy,
                'discrepancy' => $discrepancy,
                'authorized_by_user_id' => $authorizedBy?->id,
            ];
        });
    }
}

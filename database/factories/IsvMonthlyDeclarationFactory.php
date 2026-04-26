<?php

namespace Database\Factories;

use App\Models\FiscalPeriod;
use App\Models\IsvMonthlyDeclaration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IsvMonthlyDeclaration>
 *
 * Genera snapshots de declaración ISV mensual sintéticamente coherentes:
 * las cuadraturas se respetan dentro del default (ventas_totales = gravadas
 * + exentas, isv_debito = gravadas * 0.15, etc.) para que los tests que no
 * reemplazan cada campo puedan confiar en totales consistentes sin duplicar
 * la lógica del Service.
 *
 * Tests que necesiten disparar casos inválidos (totales inconsistentes,
 * saldos negativos simulados) los fuerzan con state() a mano.
 */
class IsvMonthlyDeclarationFactory extends Factory
{
    protected $model = IsvMonthlyDeclaration::class;

    public function definition(): array
    {
        // Totales base sintéticos — cantidades plausibles para una PYME hondureña.
        $ventasGravadas   = $this->faker->randomFloat(2, 50000, 500000);
        $ventasExentas    = $this->faker->randomFloat(2, 0, 20000);
        $comprasGravadas  = $this->faker->randomFloat(2, 10000, 300000);
        $comprasExentas   = $this->faker->randomFloat(2, 0, 10000);

        $isvDebito     = round($ventasGravadas * 0.15, 2);
        $isvCredito    = round($comprasGravadas * 0.15, 2);
        $retenciones   = $this->faker->randomFloat(2, 0, 5000);
        $saldoAnterior = 0.0;

        $deuda = $isvDebito - $isvCredito - $retenciones - $saldoAnterior;
        $isvAPagar            = $deuda > 0 ? round($deuda, 2) : 0.0;
        $saldoAFavorSiguiente = $deuda < 0 ? round(abs($deuda), 2) : 0.0;

        return [
            'fiscal_period_id' => FiscalPeriod::factory(),
            'declared_at'      => now(),
            'declared_by_user_id' => User::factory(),
            'siisar_acuse_number' => $this->faker->optional(0.8)->numerify('SIISAR-########'),

            'ventas_gravadas' => $ventasGravadas,
            'ventas_exentas'  => $ventasExentas,
            'ventas_totales'  => round($ventasGravadas + $ventasExentas, 2),

            'compras_gravadas' => $comprasGravadas,
            'compras_exentas'  => $comprasExentas,
            'compras_totales'  => round($comprasGravadas + $comprasExentas, 2),

            'isv_debito_fiscal'          => $isvDebito,
            'isv_credito_fiscal'         => $isvCredito,
            'isv_retenciones_recibidas'  => $retenciones,
            'saldo_a_favor_anterior'     => $saldoAnterior,
            'isv_a_pagar'                => $isvAPagar,
            'saldo_a_favor_siguiente'    => $saldoAFavorSiguiente,

            'notes' => null,

            'superseded_at'         => null,
            'superseded_by_user_id' => null,
        ];
    }

    /**
     * Declaración para un período fiscal específico. Reutiliza un
     * FiscalPeriod existente en vez de crear uno nuevo.
     */
    public function forFiscalPeriod(FiscalPeriod $period): static
    {
        return $this->state(fn () => [
            'fiscal_period_id' => $period->id,
        ]);
    }

    /**
     * Declaración supersedida (reemplazada por una rectificativa). Útil para
     * tests que necesitan simular el estado histórico después de un reopen +
     * re-declare.
     */
    public function superseded(?User $user = null): static
    {
        return $this->state(fn () => [
            'superseded_at'         => now(),
            'superseded_by_user_id' => $user?->id ?? User::factory(),
        ]);
    }

    /**
     * Totales cero — útil para probar el caso de un mes sin movimiento
     * (empresa cerrada por vacaciones) que aún debe declarar al SAR.
     */
    public function zeroed(): static
    {
        return $this->state(fn () => [
            'ventas_gravadas'           => 0,
            'ventas_exentas'            => 0,
            'ventas_totales'            => 0,
            'compras_gravadas'          => 0,
            'compras_exentas'           => 0,
            'compras_totales'           => 0,
            'isv_debito_fiscal'         => 0,
            'isv_credito_fiscal'        => 0,
            'isv_retenciones_recibidas' => 0,
            'saldo_a_favor_anterior'    => 0,
            'isv_a_pagar'               => 0,
            'saldo_a_favor_siguiente'   => 0,
        ]);
    }

    /**
     * Con un saldo a favor explícito arrastrado del mes anterior. El
     * `isv_a_pagar` / `saldo_a_favor_siguiente` se recalculan para respetar
     * la cuadratura.
     */
    public function withSaldoAnterior(float $monto): static
    {
        return $this->state(function (array $attrs) use ($monto) {
            $debito    = (float) ($attrs['isv_debito_fiscal']         ?? 0);
            $credito   = (float) ($attrs['isv_credito_fiscal']        ?? 0);
            $retenc    = (float) ($attrs['isv_retenciones_recibidas'] ?? 0);

            $deuda = $debito - $credito - $retenc - $monto;

            return [
                'saldo_a_favor_anterior'  => $monto,
                'isv_a_pagar'             => $deuda > 0 ? round($deuda, 2) : 0.0,
                'saldo_a_favor_siguiente' => $deuda < 0 ? round(abs($deuda), 2) : 0.0,
            ];
        });
    }
}

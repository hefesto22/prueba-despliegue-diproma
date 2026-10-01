<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Services\Finance\Exceptions\DeduccionDiezmoInvalidaException;

/**
 * Cálculo del diezmo de un mes — value object inmutable, sin base de datos.
 *
 *   base   = utilidad neta del sistema − pagos que el sistema no conoce
 *            (pago de empleados, etc.)
 *   diezmo = 10% de la base; si la base es cero o negativa, el diezmo es 0.
 *
 * Los gastos ya registrados en Compras NO se vuelven a restar: ya están
 * descontados en la utilidad neta.
 */
final class TitheBreakdown
{
    public const RATE = 0.10;

    private function __construct(
        public readonly float $netProfit,
        public readonly float $extraDeductions,
    ) {}

    /**
     * @param  array<int, float|int|string|null>  $deductionAmounts  Montos de los pagos agregados.
     *
     * @throws DeduccionDiezmoInvalidaException Si algún monto es negativo.
     */
    public static function from(MonthlyProfit $profit, array $deductionAmounts): self
    {
        $total = 0.0;

        foreach ($deductionAmounts as $amount) {
            $amount = (float) $amount;

            if ($amount < 0) {
                throw new DeduccionDiezmoInvalidaException($amount);
            }

            $total += $amount;
        }

        return new self($profit->netProfit(), round($total, 2));
    }

    public function base(): float
    {
        return round($this->netProfit - $this->extraDeductions, 2);
    }

    public function amount(): float
    {
        return round(max(0.0, $this->base()) * self::RATE, 2);
    }
}

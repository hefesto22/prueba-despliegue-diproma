<?php

declare(strict_types=1);

namespace App\Services\Finance;

/**
 * Utilidad de un mes calendario — value object inmutable.
 *
 *   ganancia bruta  = ventas sin ISV − costo de lo vendido
 *   utilidad neta   = ganancia bruta − compras tipo Gasto (sin ISV) − comisiones de tarjeta
 *
 * Lo producen MonthlyProfitCalculator (Escritorio y diezmo) y lo guarda el
 * diezmo como foto del momento en que se calculó.
 */
final class MonthlyProfit
{
    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly float $revenue,
        public readonly float $cost,
        public readonly float $expensePurchases,
        public readonly float $cardFees,
    ) {}

    public function grossProfit(): float
    {
        return round($this->revenue - $this->cost, 2);
    }

    /**
     * Gastos que ya están en el sistema: compras tipo Gasto + comisiones.
     */
    public function operatingExpenses(): float
    {
        return round($this->expensePurchases + $this->cardFees, 2);
    }

    public function netProfit(): float
    {
        return round($this->grossProfit() - $this->operatingExpenses(), 2);
    }
}

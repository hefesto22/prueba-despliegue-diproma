<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Tithe;
use App\Services\Finance\Exceptions\DeduccionDiezmoInvalidaException;
use App\Services\Finance\Exceptions\MesDiezmoInvalidoException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Diezmo del mes: 10% de la utilidad neta, menos los pagos del negocio que
 * el sistema no conoce (pago de empleados, etc.).
 *
 * Guarda un registro por mes con la foto de las cifras. Sacar de nuevo el
 * diezmo de un mes lo recalcula con las cifras de ese momento y reemplaza
 * sus pagos: nunca hay dos diezmos para el mismo mes.
 */
class TitheService
{
    public function __construct(
        private readonly MonthlyProfitCalculator $profits,
    ) {}

    /**
     * Calcula y guarda (o recalcula) el diezmo de un mes.
     *
     * @param  list<array{concept: string, amount: float|int|string}>  $deductions
     *
     * @throws MesDiezmoInvalidoException Si el mes todavía no empieza.
     * @throws DeduccionDiezmoInvalidaException Si algún pago es negativo.
     */
    public function save(int $year, int $month, array $deductions, ?string $notes = null): void
    {
        $this->assertMonthStarted($year, $month);

        $profit = $this->profits->forMonth($year, $month);
        $breakdown = TitheBreakdown::from($profit, array_column($deductions, 'amount'));

        DB::transaction(function () use ($year, $month, $deductions, $notes, $profit, $breakdown) {
            // Lock: dos guardados simultáneos del mismo mes no pueden mezclar
            // los pagos de uno con la foto del otro. El índice único
            // (year, month) cubre el caso de dos altas simultáneas.
            $tithe = Tithe::query()
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first() ?? new Tithe(['year' => $year, 'month' => $month]);

            $tithe->fill([
                'revenue' => $profit->revenue,
                'cost' => $profit->cost,
                'expense_purchases' => $profit->expensePurchases,
                'card_fees' => $profit->cardFees,
                'net_profit' => $profit->netProfit(),
                'extra_deductions' => $breakdown->extraDeductions,
                'base_amount' => $breakdown->base(),
                'rate' => TitheBreakdown::RATE,
                'amount' => $breakdown->amount(),
                'notes' => filled($notes) ? $notes : null,
            ])->save();

            $tithe->deductions()->delete();
            $tithe->deductions()->createMany(array_map(fn (array $deduction) => [
                'concept' => trim((string) $deduction['concept']),
                'amount' => round((float) $deduction['amount'], 2),
            ], $deductions));
        });
    }

    /**
     * Diezmo guardado de un mes, con sus pagos, o null si no se ha sacado.
     */
    public function forMonth(int $year, int $month): ?Tithe
    {
        return Tithe::query()
            ->with('deductions:id,tithe_id,concept,amount')
            ->where('year', $year)
            ->where('month', $month)
            ->first();
    }

    private function assertMonthStarted(int $year, int $month): void
    {
        $requested = CarbonImmutable::create($year, $month, 1)->startOfMonth();

        if ($requested->isAfter(CarbonImmutable::now()->startOfMonth())) {
            throw new MesDiezmoInvalidoException($year, $month);
        }
    }
}

{{--
    Cifras del modal "Diezmo del mes" (TitheWidget).

    part = 'system': la utilidad que ya calcula el sistema para el mes.
    part = 'result': pagos agregados, base y diezmo.

    @var string $part
    @var string $periodLabel
    @var \App\Services\Finance\MonthlyProfit $profit
    @var \App\Services\Finance\TitheBreakdown $breakdown
--}}
@if ($part === 'system')
    <div class="space-y-1 rounded-xl bg-gray-50 p-4 text-sm dark:bg-white/5">
        <div class="font-semibold text-gray-950 dark:text-white">Utilidad del sistema — {{ $periodLabel }}</div>
        <div class="flex justify-between text-gray-600 dark:text-gray-400">
            <span>Ventas sin ISV</span>
            <span class="tabular-nums">L {{ number_format($profit->revenue, 2) }}</span>
        </div>
        <div class="flex justify-between text-gray-600 dark:text-gray-400">
            <span>− Costo de lo vendido</span>
            <span class="tabular-nums">L {{ number_format($profit->cost, 2) }}</span>
        </div>
        <div class="flex justify-between text-gray-600 dark:text-gray-400">
            <span>− Gastos registrados en Compras</span>
            <span class="tabular-nums">L {{ number_format($profit->expensePurchases, 2) }}</span>
        </div>
        <div class="flex justify-between text-gray-600 dark:text-gray-400">
            <span>− Comisiones de tarjeta</span>
            <span class="tabular-nums">L {{ number_format($profit->cardFees, 2) }}</span>
        </div>
        <div class="flex justify-between pt-2 font-semibold text-gray-950 dark:text-white">
            <span>Utilidad neta</span>
            <span class="tabular-nums">L {{ number_format($profit->netProfit(), 2) }}</span>
        </div>
    </div>
@else
    <div class="space-y-1 rounded-xl bg-gray-50 p-4 text-sm dark:bg-white/5">
        <div class="flex justify-between text-gray-600 dark:text-gray-400">
            <span>Utilidad neta</span>
            <span class="tabular-nums">L {{ number_format($breakdown->netProfit, 2) }}</span>
        </div>
        <div class="flex justify-between text-gray-600 dark:text-gray-400">
            <span>− Pagos agregados</span>
            <span class="tabular-nums">L {{ number_format($breakdown->extraDeductions, 2) }}</span>
        </div>
        <div class="flex justify-between text-gray-600 dark:text-gray-400">
            <span>Base para el diezmo</span>
            <span class="tabular-nums">L {{ number_format($breakdown->base(), 2) }}</span>
        </div>
        <div class="flex items-baseline justify-between pt-2">
            <span class="text-base font-semibold text-gray-950 dark:text-white">Diezmo (10%)</span>
            <span class="text-2xl font-bold tabular-nums text-primary-600 dark:text-primary-400">
                L {{ number_format($breakdown->amount(), 2) }}
            </span>
        </div>
        @if ($breakdown->base() <= 0)
            <div class="text-xs italic text-gray-500 dark:text-gray-400">
                No hubo ganancia después de los pagos: el diezmo es L 0.00.
            </div>
        @endif
    </div>
@endif

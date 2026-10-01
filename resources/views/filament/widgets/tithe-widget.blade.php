{{--
    Escritorio: diezmo del mes (TitheWidget).

    @var string $currentLabel  "Septiembre 2026"
    @var \App\Models\Tithe|null $current  diezmo guardado del mes en curso
    @var \Illuminate\Support\Collection<int, \App\Models\Tithe> $recent  últimos guardados
--}}
<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-heart" heading="Diezmo">
        <x-slot name="afterHeader">
            {{ $this->calculateTitheAction }}
        </x-slot>

        <div class="space-y-3 text-sm">
            <div class="flex items-baseline justify-between">
                <span class="text-gray-700 dark:text-gray-300">{{ $currentLabel }}</span>
                @if ($current)
                    <span class="text-2xl font-bold tabular-nums text-primary-600 dark:text-primary-400">
                        L {{ number_format((float) $current->amount, 2) }}
                    </span>
                @else
                    <span class="italic text-gray-500 dark:text-gray-400">Sin calcular</span>
                @endif
            </div>

            @if ($current)
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    Utilidad L {{ number_format((float) $current->net_profit, 2) }}
                    − pagos agregados L {{ number_format((float) $current->extra_deductions, 2) }}
                    = base L {{ number_format((float) $current->base_amount, 2) }}.
                    Calculado el {{ $current->updated_at?->format('d/m/Y H:i') }}.
                </div>
            @endif

            @if ($recent->isNotEmpty())
                <div class="space-y-1">
                    <div class="font-semibold text-gray-950 dark:text-white">Últimos diezmos</div>
                    @foreach ($recent as $tithe)
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span>{{ $tithe->periodLabel() }}</span>
                            <span class="tabular-nums">L {{ number_format((float) $tithe->amount, 2) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>

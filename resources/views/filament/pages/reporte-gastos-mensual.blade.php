<x-filament-panels::page>
    <form>
        {{ $this->form }}
    </form>

    @if ($this->report === null)
        {{-- Estado inicial: instrucciones cuando no hay reporte cargado --}}
        <x-filament::section>
            <x-slot name="heading">¿Cómo funciona?</x-slot>

            <div class="prose dark:prose-invert max-w-none text-sm leading-relaxed">
                <p>
                    Este reporte muestra los gastos del período y los KPIs que el contador necesita
                    para el pago mensual de ISV (Formulario 201, día 10 de cada mes).
                </p>
                <ul class="list-disc pl-6 space-y-1">
                    <li><strong>Crédito fiscal deducible:</strong> el monto que el contador suma al F201 como ISV pagado en compras y gastos.</li>
                    <li><strong>Deducibles incompletos:</strong> gastos marcados deducibles que no tienen RTN, # factura o CAI completos. SAR puede rechazar el crédito fiscal de estas filas en una auditoría.</li>
                    <li><strong>Impacto en caja:</strong> separa gastos pagados en efectivo (afectan saldo físico de cajas) de los pagados con tarjeta / transferencia / cheque.</li>
                    <li><strong>Sucursal opcional:</strong> vacío = company-wide. Útil filtrar para conciliar gastos por punto de venta.</li>
                </ul>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">
                    Presione <strong>Cargar reporte</strong> para ver los KPIs en pantalla. Después puede <strong>Exportar a Excel</strong> con dos hojas (Resumen + Detalle).
                </p>
            </div>
        </x-filament::section>
    @else
        @php
            $s = $this->report->summary;
        @endphp

        {{-- Encabezado del reporte cargado --}}
        <x-filament::section>
            <x-slot name="heading">
                Reporte de {{ $s->periodLabel() }}
            </x-slot>

            {{-- Alerta si hay deducibles incompletos --}}
            @if ($s->hasIncompleteWarnings())
                <div class="rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-900/30 dark:border-amber-700 p-4 mb-4 flex items-start gap-3">
                    <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-amber-700 dark:text-amber-300 flex-shrink-0 mt-0.5" />
                    <div class="text-sm">
                        <p class="font-semibold text-amber-900 dark:text-amber-100">
                            {{ $s->deduciblesIncompletosCount }} {{ $s->deduciblesIncompletosCount === 1 ? 'gasto deducible incompleto' : 'gastos deducibles incompletos' }}
                        </p>
                        <p class="text-amber-800 dark:text-amber-200 mt-1">
                            Hay gastos marcados deducibles sin RTN, # de factura o CAI completos. Antes de declarar al SAR, edite cada uno y complete los datos del proveedor — de lo contrario, el crédito fiscal de esas filas puede ser rechazado en auditoría.
                        </p>
                        <p class="text-amber-700 dark:text-amber-300 text-xs mt-2">
                            Las filas afectadas aparecen resaltadas en la hoja "Detalle" del Excel.
                        </p>
                    </div>
                </div>
            @endif

            {{-- KPIs principales en grid --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {{-- Total del período --}}
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium">Total gastos</p>
                    <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mt-1">
                        L. {{ number_format($s->gastosTotal, 2) }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        {{ $s->gastosCount }} {{ $s->gastosCount === 1 ? 'registro' : 'registros' }}
                    </p>
                </div>

                {{-- Crédito fiscal deducible (KPI verde, lo que va al F201) --}}
                <div class="rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 p-4">
                    <p class="text-xs text-emerald-700 dark:text-emerald-300 uppercase tracking-wide font-medium">Crédito fiscal deducible</p>
                    <p class="text-2xl font-semibold text-emerald-900 dark:text-emerald-100 mt-1">
                        L. {{ number_format($s->creditoFiscalDeducible, 2) }}
                    </p>
                    <p class="text-xs text-emerald-700 dark:text-emerald-400 mt-1">
                        Va al F201 como ISV de compras
                    </p>
                </div>

                {{-- Total deducibles --}}
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium">Total deducibles</p>
                    <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mt-1">
                        L. {{ number_format($s->deduciblesTotal, 2) }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        {{ $s->deduciblesCount }} {{ $s->deduciblesCount === 1 ? 'gasto' : 'gastos' }}
                    </p>
                </div>

                {{-- No deducibles --}}
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium">No deducibles</p>
                    <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mt-1">
                        L. {{ number_format($s->noDeduciblesTotal, 2) }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        {{ $s->noDeduciblesCount }} {{ $s->noDeduciblesCount === 1 ? 'gasto' : 'gastos' }}
                    </p>
                </div>
            </div>

            {{-- Impacto en caja (segunda fila de KPIs, más compacta) --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium">Pagado en efectivo</p>
                    <div class="flex items-baseline gap-2 mt-1">
                        <p class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                            L. {{ number_format($s->cashTotal, 2) }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            ({{ $s->cashCount }} {{ $s->cashCount === 1 ? 'gasto' : 'gastos' }} — afectan caja)
                        </p>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium">Otros métodos</p>
                    <div class="flex items-baseline gap-2 mt-1">
                        <p class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                            L. {{ number_format($s->nonCashTotal, 2) }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            ({{ $s->nonCashCount }} {{ $s->nonCashCount === 1 ? 'gasto' : 'gastos' }} — tarjeta/transferencia/cheque)
                        </p>
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- Desglose por categoría --}}
        @if (! empty($s->byCategory))
            <x-filament::section collapsible>
                <x-slot name="heading">Desglose por categoría</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="text-left py-2 px-3 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-medium">Categoría</th>
                                <th class="text-right py-2 px-3 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-medium">Cantidad</th>
                                <th class="text-right py-2 px-3 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($s->byCategory as $bucket)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 px-3 text-gray-900 dark:text-gray-100">{{ $bucket['label'] }}</td>
                                    <td class="py-2 px-3 text-right text-gray-700 dark:text-gray-300">{{ $bucket['count'] }}</td>
                                    <td class="py-2 px-3 text-right font-medium text-gray-900 dark:text-gray-100">L. {{ number_format($bucket['total'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        {{-- Desglose por método de pago --}}
        @if (! empty($s->byPaymentMethod))
            <x-filament::section collapsible>
                <x-slot name="heading">Desglose por método de pago</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="text-left py-2 px-3 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-medium">Método</th>
                                <th class="text-right py-2 px-3 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-medium">Cantidad</th>
                                <th class="text-right py-2 px-3 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($s->byPaymentMethod as $bucket)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 px-3 text-gray-900 dark:text-gray-100">{{ $bucket['label'] }}</td>
                                    <td class="py-2 px-3 text-right text-gray-700 dark:text-gray-300">{{ $bucket['count'] }}</td>
                                    <td class="py-2 px-3 text-right font-medium text-gray-900 dark:text-gray-100">L. {{ number_format($bucket['total'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        {{-- Desglose por sucursal --}}
        @if (! empty($s->byEstablishment))
            <x-filament::section collapsible>
                <x-slot name="heading">Desglose por sucursal</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="text-left py-2 px-3 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-medium">Sucursal</th>
                                <th class="text-right py-2 px-3 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-medium">Cantidad</th>
                                <th class="text-right py-2 px-3 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($s->byEstablishment as $bucket)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 px-3 text-gray-900 dark:text-gray-100">{{ $bucket['name'] }}</td>
                                    <td class="py-2 px-3 text-right text-gray-700 dark:text-gray-300">{{ $bucket['count'] }}</td>
                                    <td class="py-2 px-3 text-right font-medium text-gray-900 dark:text-gray-100">L. {{ number_format($bucket['total'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>

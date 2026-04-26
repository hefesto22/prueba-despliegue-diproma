{{--
    Resumen de totales de la compra — versión inline al pie del repeater.

    Patrón clásico de factura: totales alineados a la derecha, debajo de
    los items. Se actualiza en vivo cuando los items del repeater cambian
    (live() / live(onBlur: true) en quantity y unit_cost) y cuando cambia
    el tipo de documento (live() en document_type del nivel raíz).

    Dos modos de presentación según `separates_isv`:
      - true (factura/NC/ND): muestra desglose Subtotal gravado / Subtotal
        exento / ISV / Total — reflejo del crédito fiscal SAR.
      - false (Recibo Interno): muestra solo el Total — RI no separa ISV
        porque no hay documento SAR detrás. Mostrar "ISV L 0.00" sería
        ruido; mostrarlo separado generaría la falsa impresión de crédito
        fiscal disponible.

    @var array $summary  ['items_count', 'taxable', 'exempt', 'isv', 'total', 'separates_isv']
--}}
@php
    $hasItems = ($summary['items_count'] ?? 0) > 0;
    $separatesIsv = (bool) ($summary['separates_isv'] ?? true);
@endphp

<div class="fi-purchase-summary flex w-full justify-end pt-2">
    @if ($hasItems)
        <div class="w-full max-w-md space-y-2 rounded-xl bg-gray-50 p-4 dark:bg-white/5">
            @if ($separatesIsv)
                @if ($summary['taxable'] > 0)
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600 dark:text-gray-400">Subtotal gravado</span>
                        <span class="font-medium tabular-nums text-gray-950 dark:text-white">
                            L {{ number_format($summary['taxable'], 2) }}
                        </span>
                    </div>
                @endif

                @if ($summary['exempt'] > 0)
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-600 dark:text-gray-400">Subtotal exento</span>
                        <span class="font-medium tabular-nums text-gray-950 dark:text-white">
                            L {{ number_format($summary['exempt'], 2) }}
                        </span>
                    </div>
                @endif

                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-600 dark:text-gray-400">ISV 15%</span>
                    <span class="font-medium tabular-nums text-gray-950 dark:text-white">
                        L {{ number_format($summary['isv'], 2) }}
                    </span>
                </div>
            @else
                {{-- Recibo Interno: aviso explícito de que no hay desglose fiscal. --}}
                <div class="flex items-center justify-between text-xs italic text-gray-500 dark:text-gray-400">
                    <span>Sin desglose de ISV — Recibo Interno no genera crédito fiscal</span>
                </div>
            @endif

            <div class="@if($separatesIsv) border-t border-gray-200 pt-2 dark:border-white/10 @endif">
                <div class="flex items-baseline justify-between">
                    <span class="text-base font-semibold text-gray-950 dark:text-white">Total</span>
                    <span class="text-2xl font-bold tabular-nums text-primary-600 dark:text-primary-400">
                        L {{ number_format($summary['total'], 2) }}
                    </span>
                </div>
            </div>
        </div>
    @else
        <div class="text-sm italic text-gray-400 dark:text-gray-500">
            Agregue productos para ver los totales
        </div>
    @endif
</div>

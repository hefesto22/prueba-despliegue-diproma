{{--
    Total del documento de compra — al pie de la sección "Montos del documento".

    El total NO es un campo editable: se deriva de exento + gravado + ISV en
    PurchaseDocumentAmounts, así que el invariante no se puede romper. Se
    muestra grande para que el operador lo compare contra el total impreso en
    la factura: si no coincide, transcribió mal algún importe.

    Aviso de ISV: si el ISV difiere de gravado × 15% más allá de la tolerancia
    de redondeo, se avisa SIN bloquear — manda lo que dice el documento.

    @var \App\Services\Purchases\PurchaseDocumentAmounts|null $amounts  null mientras los montos son inválidos o están vacíos
    @var bool  $isReciboInterno
--}}
<div class="fi-purchase-summary flex w-full justify-end pt-2">
    @if ($amounts)
        <div class="w-full max-w-md space-y-2 rounded-xl bg-gray-50 p-4 dark:bg-white/5">
            @if ($isReciboInterno)
                <div class="text-xs italic text-gray-500 dark:text-gray-400">
                    Sin desglose de ISV — el Recibo Interno no genera crédito fiscal.
                </div>
            @elseif (! $amounts->isvMatchesRate())
                <div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-900/20 dark:text-amber-200">
                    <strong>Revise el ISV:</strong>
                    el 15% del importe gravado es
                    L {{ number_format(\App\Services\Purchases\PurchaseDocumentAmounts::suggestedIsv($amounts->taxable), 2) }}
                    y se ingresó L {{ number_format($amounts->isv, 2) }}
                    (diferencia L {{ number_format($amounts->isvDifference(), 2) }}).
                    Si la factura dice exactamente eso, déjelo así: se guarda lo que dice el documento.
                </div>
            @endif

            <div class="flex items-baseline justify-between">
                <span class="text-base font-semibold text-gray-950 dark:text-white">Total del documento</span>
                <span class="text-2xl font-bold tabular-nums text-primary-600 dark:text-primary-400">
                    L {{ number_format($amounts->total(), 2) }}
                </span>
            </div>

            @unless ($isReciboInterno)
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    Debe coincidir con el total impreso en la factura.
                </div>
            @endunless
        </div>
    @else
        <div class="text-sm italic text-gray-400 dark:text-gray-500">
            Ingrese los importes del documento para ver el total.
        </div>
    @endif
</div>

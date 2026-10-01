{{--
    Resumen de la compra — columna derecha del formulario de Compras.

    Muestra el total del documento y lo que pasará al confirmar (Libro de
    Compras, utilidad). Registrar una compra no mueve la caja. El total NO es un campo editable: se deriva de
    exento + gravado + ISV en PurchaseDocumentAmounts, así que el invariante
    no se puede romper. Se muestra grande para que el operador lo compare
    contra el total impreso en el documento.

    Aviso de ISV: si el ISV difiere de gravado × 15% más allá de la tolerancia
    de redondeo, se avisa SIN bloquear — manda lo que dice el documento.

    Solo usa clases que ya existen en el tema compilado (public/build), para
    no tener que recompilar assets.

    @var \App\Services\Purchases\PurchaseDocumentAmounts|null $amounts  null mientras los montos son inválidos o están vacíos
    @var \App\Enums\PurchaseKind|null $kind
    @var bool $isReciboInterno
    @var \App\Enums\PaymentMethod|null $paymentMethod
    @var string|null $supplierName
    @var string|null $purchaseNumber  solo al editar
--}}
<div class="fi-purchase-summary space-y-3 text-sm">
    <div class="space-y-1">
        @if ($purchaseNumber)
            <div class="font-semibold text-gray-950 dark:text-white">{{ $purchaseNumber }}</div>
        @endif
        <div class="text-gray-700 dark:text-gray-300">
            {{ $kind?->getLabel() ?? 'Compra' }} · {{ $isReciboInterno ? 'Recibo sin CAI' : 'Factura con CAI' }}
        </div>
        @if ($supplierName)
            <div class="text-gray-500 dark:text-gray-400">{{ $supplierName }}</div>
        @endif
        @if ($paymentMethod)
            <div class="text-gray-500 dark:text-gray-400">Pagado con {{ mb_strtolower($paymentMethod->getLabel()) }}</div>
        @endif
    </div>

    @if ($amounts)
        <div class="space-y-1 rounded-xl bg-gray-50 p-4 dark:bg-white/5">
            @unless ($isReciboInterno)
                <div class="flex justify-between text-gray-600 dark:text-gray-400">
                    <span>Exento</span>
                    <span class="tabular-nums">L {{ number_format($amounts->exempt, 2) }}</span>
                </div>
                <div class="flex justify-between text-gray-600 dark:text-gray-400">
                    <span>Gravado 15%</span>
                    <span class="tabular-nums">L {{ number_format($amounts->taxable, 2) }}</span>
                </div>
                <div class="flex justify-between text-gray-600 dark:text-gray-400">
                    <span>ISV</span>
                    <span class="tabular-nums">L {{ number_format($amounts->isv, 2) }}</span>
                </div>
            @endunless
            <div class="flex items-baseline justify-between pt-2">
                <span class="text-base font-semibold text-gray-950 dark:text-white">Total</span>
                <span class="text-2xl font-bold tabular-nums text-primary-600 dark:text-primary-400">
                    L {{ number_format($amounts->total(), 2) }}
                </span>
            </div>
        </div>

        @if (! $isReciboInterno && ! $amounts->isvMatchesRate())
            <div class="rounded-lg bg-amber-50 p-3 text-amber-900 dark:bg-amber-900/20 dark:text-amber-200">
                <strong>Revise el ISV:</strong>
                el 15% del gravado es
                L {{ number_format(\App\Services\Purchases\PurchaseDocumentAmounts::suggestedIsv($amounts->taxable), 2) }}
                y se ingresó L {{ number_format($amounts->isv, 2) }}.
                Si la factura dice exactamente eso, déjelo así.
            </div>
        @endif

        <div class="space-y-1 text-gray-700 dark:text-gray-300">
            <div class="font-semibold text-gray-950 dark:text-white">Al confirmar:</div>
            <ul class="list-disc list-inside space-y-1">
                @if ($isReciboInterno)
                    <li>No entra al Libro de Compras.</li>
                @else
                    <li>Entra al Libro de Compras con L {{ number_format($amounts->isv, 2) }} de crédito fiscal.</li>
                @endif

                @if ($kind === \App\Enums\PurchaseKind::Gasto)
                    <li>Resta de la utilidad del mes.</li>
                @else
                    <li>El inventario se ingresa desde Productos.</li>
                @endif
            </ul>
        </div>
    @else
        <div class="italic text-gray-400 dark:text-gray-500">
            Ingrese el monto del documento para ver el total.
        </div>
    @endif
</div>

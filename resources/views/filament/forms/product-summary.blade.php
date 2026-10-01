{{--
    Resumen del producto — columna derecha del formulario de Productos.

    Muestra el nombre y SKU que se van a generar (Product::autoGenerateName /
    autoGenerateSku usan la misma regla), el desglose del precio y la
    ganancia, y qué pasa con el stock al guardar.

    La ganancia se calcula sobre el precio SIN ISV: el ISV cobrado se entrega
    al SAR, no es del negocio. El margen es sobre el costo (mismo criterio que
    tenía el formulario antes del rediseño).

    Solo usa clases que ya existen en el tema compilado (public/build), para
    no tener que recompilar assets.

    @var string|null $name
    @var string|null $sku
    @var bool $skuIsPreview  true al crear: el correlativo se asigna al guardar
    @var string|null $typeLabel
    @var string|null $conditionLabel
    @var bool $isService
    @var bool $isGravado
    @var float|null $cost
    @var float|null $sale  precio que paga el cliente (con ISV si es gravado)
    @var float|null $saleBase  precio sin ISV
    @var float|null $isv
    @var float|null $profit  null mientras falte costo o precio
    @var int $stock
    @var bool $isCreating
--}}
@php
    $margin = $profit !== null && $cost > 0 ? round($profit / $cost * 100, 1) : null;
    $marginClass = match (true) {
        $margin === null => '',
        $margin >= 20 => 'text-success-600 dark:text-success-400',
        $margin >= 10 => 'text-warning-600 dark:text-warning-400',
        default => 'text-danger-600 dark:text-danger-400',
    };
@endphp

<div class="fi-product-summary space-y-3 text-sm">
    <div class="space-y-1">
        @if ($name)
            <div class="text-base font-semibold text-gray-950 dark:text-white">{{ $name }}</div>
        @else
            <div class="italic text-gray-400 dark:text-gray-500">Elija el tipo de producto.</div>
        @endif

        @if ($sku)
            <div class="text-xs text-gray-500 dark:text-gray-400">
                SKU <span class="font-mono">{{ $sku }}</span>@if ($skuIsPreview) · se asigna al guardar @endif
            </div>
        @endif

        @if ($typeLabel)
            <div class="text-gray-700 dark:text-gray-300">
                {{ $typeLabel }}@if ($conditionLabel) · {{ $conditionLabel }} @endif
            </div>
        @endif
    </div>

    @if ($sale !== null)
        <div class="space-y-1 rounded-xl bg-gray-50 p-4 dark:bg-white/5">
            @if ($isGravado)
                <div class="flex justify-between text-gray-600 dark:text-gray-400">
                    <span>Precio sin ISV</span>
                    <span class="tabular-nums">L {{ number_format($saleBase, 2) }}</span>
                </div>
                <div class="flex justify-between text-gray-600 dark:text-gray-400">
                    <span>ISV 15%</span>
                    <span class="tabular-nums">L {{ number_format($isv, 2) }}</span>
                </div>
            @endif
            <div class="flex items-baseline justify-between pt-2">
                <span class="text-base font-semibold text-gray-950 dark:text-white">Precio de venta</span>
                <span class="text-2xl font-bold tabular-nums text-primary-600 dark:text-primary-400">
                    L {{ number_format($sale, 2) }}
                </span>
            </div>
            @unless ($isGravado)
                <div class="text-right text-xs text-gray-500 dark:text-gray-400">Exento de ISV</div>
            @endunless

            @if ($cost !== null)
                <div class="flex justify-between pt-2 text-gray-600 dark:text-gray-400">
                    <span>Costo</span>
                    <span class="tabular-nums">L {{ number_format($cost, 2) }}</span>
                </div>
            @endif

            @if ($profit !== null)
                <div class="flex items-baseline justify-between font-semibold {{ $marginClass }}">
                    <span>Ganancia</span>
                    <span class="tabular-nums">L {{ number_format($profit, 2) }} ({{ $margin }}%)</span>
                </div>
            @endif
        </div>

        @if ($profit !== null && $profit < 0)
            <div class="rounded-lg bg-red-50 p-3 text-red-900 dark:bg-red-900/20 dark:text-red-200">
                <strong>Revise el precio:</strong>
                sin ISV queda por debajo del costo.
            </div>
        @endif
    @elseif ($isService)
        <div class="italic text-gray-400 dark:text-gray-500">
            Precio variable: se ajusta al facturar.
        </div>
    @else
        <div class="italic text-gray-400 dark:text-gray-500">
            Ingrese costo y precio para ver la ganancia.
        </div>
    @endif

    <div class="space-y-1 text-gray-700 dark:text-gray-300">
        @if ($isService)
            <div>Servicio: no lleva inventario.</div>
        @elseif ($isCreating)
            <div><span class="font-semibold text-gray-950 dark:text-white">Stock inicial:</span> {{ $stock }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ $stock > 0 ? 'Entra al Kardex como carga inicial.' : 'Se guarda sin existencias.' }}
            </div>
        @else
            <div><span class="font-semibold text-gray-950 dark:text-white">Stock:</span> {{ $stock }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Si lo cambia, el Kardex registra el ajuste.</div>
        @endif
    </div>
</div>

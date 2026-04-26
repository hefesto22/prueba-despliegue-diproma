{{--
    Banner contextual del producto seleccionado dentro de un item de compra.

    Muestra de un vistazo: condición (Nuevo/Usado), tratamiento fiscal
    (Gravado/Exento), stock actual y costo histórico. Es la información
    que el operador necesita para decidir si la compra tiene sentido
    (¿ya hay mucho stock? ¿el costo cambió mucho?).

    Usa componentes Filament nativos para mantener consistencia visual
    con el resto del panel y soportar light/dark mode automáticamente.

    @var \App\Models\Product $product
--}}
@php
    $isNew = $product->condition === \App\Enums\ProductCondition::New;
    $isGravado = $product->tax_type === \App\Enums\TaxType::Gravado15;
    $stock = (int) $product->stock;
    $hasStock = $stock > 0;
@endphp

<div class="fi-purchase-product-info flex flex-wrap items-center gap-2 pt-1 text-sm">
    <x-filament::badge
        :color="$isNew ? 'success' : 'warning'"
        :icon="$isNew ? 'heroicon-m-sparkles' : 'heroicon-m-arrow-path'"
        size="sm"
    >
        {{ $isNew ? 'Nuevo' : 'Usado' }}
    </x-filament::badge>

    <x-filament::badge
        :color="$isGravado ? 'info' : 'gray'"
        :icon="$isGravado ? 'heroicon-m-receipt-percent' : 'heroicon-m-no-symbol'"
        size="sm"
    >
        {{ $isGravado ? 'Gravado 15%' : 'Exento de ISV' }}
    </x-filament::badge>

    <div class="ms-1 flex items-center gap-3 text-gray-500 dark:text-gray-400">
        <span class="inline-flex items-center gap-1">
            <span>Stock:</span>
            @if ($hasStock)
                <span class="font-semibold text-gray-950 dark:text-white">{{ $stock }} uds.</span>
            @else
                <span class="font-semibold text-danger-600 dark:text-danger-400">Sin stock</span>
            @endif
        </span>

        <span class="inline-flex items-center gap-1">
            <span>Costo histórico:</span>
            <span class="font-semibold text-gray-950 dark:text-white">
                L {{ number_format((float) $product->cost_price, 2) }}
            </span>
        </span>
    </div>
</div>

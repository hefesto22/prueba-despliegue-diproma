{{--
    Opción de producto para el Select del Repeater de Compras.

    Se renderiza tanto en el dropdown de búsqueda como en el label del
    producto ya seleccionado (getOptionLabelUsing). Usa componentes
    nativos de Filament para los badges, garantizando consistencia
    visual con el resto del panel y compatibilidad light/dark mode sin
    definir colores manualmente.

    Todas las variables provienen del modelo Product, pero se escapan
    con {{ }} (Blade) que aplica htmlspecialchars por defecto — defensa
    en profundidad contra XSS si algún SKU/nombre contuviera HTML.

    @var \App\Models\Product $product
--}}
@php
    $isNew = $product->condition === \App\Enums\ProductCondition::New;
    $stock = (int) $product->stock;
    $hasStock = $stock > 0;
@endphp

<div class="fi-purchase-product-option flex items-center gap-3 py-1">
    <x-filament::badge
        :color="$isNew ? 'success' : 'warning'"
        size="xs"
    >
        {{ strtoupper($product->condition->getLabel()) }}
    </x-filament::badge>

    <div class="min-w-0 flex-1 overflow-hidden">
        <div class="truncate text-sm font-medium text-gray-950 dark:text-white">
            {{ $product->name }}
        </div>
        <div class="truncate text-xs text-gray-500 dark:text-gray-400">
            <span class="font-mono">{{ $product->sku }}</span>
            <span class="mx-1">·</span>
            L {{ number_format((float) $product->cost_price, 2) }}
            <span class="mx-1">·</span>
            @if ($hasStock)
                <span>{{ $stock }} en stock</span>
            @else
                <span class="font-semibold text-danger-600 dark:text-danger-400">Sin stock</span>
            @endif
        </div>
    </div>
</div>

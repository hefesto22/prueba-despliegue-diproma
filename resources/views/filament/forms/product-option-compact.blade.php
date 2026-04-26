{{--
    Render compacto del producto para cuando YA ESTÁ SELECCIONADO en el Select.

    A diferencia de product-option.blade.php (usado en el dropdown de búsqueda),
    este render es minimalista: solo lo justo para identificar el producto
    dentro del input del Select sin que el texto se desborde y pise los
    campos vecinos (Cantidad, Costo c/u).

    La información rica (condición + tax + stock + costo histórico) la muestra
    el banner inferior (product-info-banner.blade.php) que aparece debajo del
    Select cuando hay un producto seleccionado — así no duplicamos data.

    Estructura:
      [BADGE]  Nombre del producto truncado · SKU

    El truncate funciona porque:
      1. min-w-0 fuerza al flex-item a poder encogerse por debajo de su
         tamaño intrínseco (sin esto el contenedor padre crece al tamaño del
         texto y rompe el layout).
      2. overflow-hidden + text-ellipsis en el bloque del nombre corta con
         "…" cuando no cabe.

    @var \App\Models\Product $product
--}}
@php
    $isNew = $product->condition === \App\Enums\ProductCondition::New;
@endphp

<div class="fi-purchase-product-selected flex min-w-0 items-center gap-2 overflow-hidden">
    <x-filament::badge
        :color="$isNew ? 'success' : 'warning'"
        size="xs"
    >
        {{ strtoupper($product->condition->getLabel()) }}
    </x-filament::badge>

    <span class="min-w-0 flex-1 truncate text-sm text-gray-950 dark:text-white">
        <span class="font-medium">{{ $product->name }}</span>
        <span class="mx-1 text-gray-400">·</span>
        <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $product->sku }}</span>
    </span>
</div>

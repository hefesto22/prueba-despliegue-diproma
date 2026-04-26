<x-filament-panels::page>
    <form>
        {{ $this->form }}
    </form>

    <x-filament::section>
        <x-slot name="heading">¿Cómo funciona?</x-slot>

        <div class="prose dark:prose-invert max-w-none text-sm leading-relaxed">
            <p>
                Los libros se generan <strong>on-demand</strong> sobre los documentos del período seleccionado.
                No requieren que el período esté declarado — puede descargarlos incluso para el mes en curso
                (útil para conciliaciones internas o revisiones con el contador antes del cierre).
            </p>
            <ul class="list-disc pl-6 space-y-1">
                <li><strong>Libro de Ventas:</strong> facturas (tipo 01) y notas de crédito (tipo 03) emitidas en el período.</li>
                <li><strong>Libro de Compras:</strong> facturas recibidas de proveedores con CAI válido. No incluye compras sin documento fiscal.</li>
                <li><strong>Sucursal opcional:</strong> déjelo vacío para generar el libro <em>company-wide</em>, que es como se declara al SAR. Filtrar por sucursal es útil solo para conciliaciones internas.</li>
            </ul>
        </div>
    </x-filament::section>
</x-filament-panels::page>

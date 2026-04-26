<x-filament-panels::page>
    {{--
        Vista embebida del recibo de cierre / corte parcial.

        Se renderiza con iframe en lugar de inline porque el blade del recibo
        (cash-sessions/print) tiene su propio `<html>`, `<body>` y un CSS
        autocontenido que define background, font-size, márgenes, etc. Si lo
        montáramos inline esos estilos chocarían con el CSS de Filament. El
        iframe aísla por completo los dos contextos.

        Beneficio adicional: window.print() del iframe imprime SOLO el recibo,
        sin sidebar/navbar. La función `printReceipt()` se expone en window
        para que el header action la invoque vía Alpine.

        El iframe se autoajusta en altura para que no aparezca scroll interno
        — el contenido del recibo (1 página Letter) cabe completo y se ve como
        una hoja dentro del panel.
    --}}
    <div
        x-data="{
            // Resize: el iframe arranca con una altura mínima razonable y se
            // ajusta al contentDocument una vez que el load termina. Si el
            // recibo crece (sesión con muchos movimientos), el iframe crece
            // con él en vez de mostrar scrollbar interno.
            //
            // El botón 'Imprimir / Guardar como PDF' vive DENTRO del propio
            // recibo (en cash-sessions/print.blade.php), por eso esta vista
            // exterior no necesita exponer ningún handler de impresión.
            resizeIframe(iframe) {
                try {
                    const doc = iframe.contentDocument || iframe.contentWindow.document;
                    if (!doc) return;
                    // +20px de margen para evitar scroll por redondeo subpíxel.
                    const height = doc.documentElement.scrollHeight + 20;
                    iframe.style.height = height + 'px';
                } catch (e) {
                    // Cross-origin no aplica acá (mismo dominio) pero por
                    // seguridad: si algo falla, queda con la altura por
                    // defecto y aparece scroll, no rompe la página.
                    console.warn('No se pudo autoajustar la altura del recibo', e);
                }
            },
        }"
        class="w-full"
    >
        {{--
            El contenedor da la apariencia de "documento sobre el escritorio":
            fondo gris claro tipo papel, sombra sutil. El iframe en sí no tiene
            borde para que se vea como una hoja flotante.
        --}}
        <div class="rounded-xl bg-gray-100 p-4 shadow-sm dark:bg-gray-900/50">
            <iframe
                x-ref="receipt"
                src="{{ route('cash-sessions.print', $this->record) }}"
                @load="resizeIframe($event.target)"
                class="block w-full rounded-lg border-0 bg-white shadow-md"
                style="min-height: 600px;"
                title="Hoja de cierre de sesión #{{ $this->record->id }}"
            ></iframe>
        </div>
    </div>
</x-filament-panels::page>

<x-filament-panels::page>
    {{--
        Vista embebida de la factura imprimible.

        Mismo patrón que la página de impresión de sesiones de caja: iframe
        en vez de inline porque la blade `invoices.print` tiene su propio
        `<html>`, `<body>` y CSS autocontenido (background, font-size,
        márgenes, paleta navy SAR). Si lo montáramos inline esos estilos
        chocarían con el CSS de Filament. El iframe aísla los dos contextos.

        Beneficio adicional: window.print() del iframe imprime SOLO la
        factura, sin sidebar/navbar. El botón "Imprimir" vive DENTRO del
        propio recibo (en la blade que sirve invoices.print), por eso esta
        vista exterior no necesita exponer ningún handler de impresión.

        El iframe se autoajusta en altura para que no aparezca scroll interno
        — la factura cabe en una página Letter completa y se ve como una
        hoja flotante dentro del panel.
    --}}
    <div
        x-data="{
            // Resize: el iframe arranca con altura mínima razonable y se
            // ajusta al contentDocument una vez que el load termina. Si la
            // factura crece (muchos items), el iframe crece con ella en vez
            // de mostrar scrollbar interno.
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
                    console.warn('No se pudo autoajustar la altura de la factura', e);
                }
            },
        }"
        class="w-full"
    >
        {{--
            Contenedor "documento sobre el escritorio": fondo gris claro tipo
            papel, sombra sutil. El iframe en sí no tiene borde para que se
            vea como una hoja flotante.
        --}}
        <div class="rounded-xl bg-gray-100 p-4 shadow-sm dark:bg-gray-900/50">
            <iframe
                x-ref="invoice"
                src="{{ route('invoices.print', $this->record) }}"
                @load="resizeIframe($event.target)"
                class="block w-full rounded-lg border-0 bg-white shadow-md"
                style="min-height: 600px;"
                title="Factura {{ $this->record->invoice_number }}"
            ></iframe>
        </div>
    </div>
</x-filament-panels::page>

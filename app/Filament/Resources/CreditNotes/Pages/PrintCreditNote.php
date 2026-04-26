<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Models\CreditNote;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;

/**
 * Página Filament que embebe la Nota de Crédito imprimible dentro del panel
 * admin (sidebar y navbar visibles) en un iframe.
 *
 * Mismo patrón que `PrintInvoice` y `PrintCashSession`: el iframe carga la
 * ruta web `credit-notes.print` — eso aísla el CSS autocontenido del recibo
 * (que asume `html, body` propios) del CSS de Filament, evitando choques.
 * Además permite que el botón "Imprimir" del recibo llame a `window.print()`
 * sobre el propio iframe y se imprima SOLO la NC, no el chrome del panel.
 *
 * Autorización doble:
 *   1. `Gate::authorize('view', ...)` en el mount — blinda el acceso directo
 *      por URL aunque el usuario conozca el ID.
 *   2. La ruta web subyacente (`credit-notes.print`) también valida
 *      `Gate::authorize('view', ...)` dentro de `CreditNotePrintController` —
 *      defense in depth: si alguien carga el iframe con un ID ajeno, el
 *      controller igual responde 403.
 *
 * No aparece en navegación — solo se llega vía action "Imprimir" desde la
 * tabla de NC, desde ViewCreditNote o desde el notification action "Imprimir
 * NC" que dispara InvoicesTable tras emitir la NC.
 */
class PrintCreditNote extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CreditNoteResource::class;

    protected string $view = 'filament.resources.credit-notes.pages.print-credit-note';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);

        Gate::authorize('view', $this->record);
    }

    /**
     * Title del <title> HTML de la pestaña — único lugar donde aparece el
     * número de NC, para que el usuario distinga la pestaña si tiene varias
     * abiertas.
     */
    public function getTitle(): string
    {
        /** @var CreditNote $record */
        $record = $this->record;

        return "NC {$record->credit_note_number}";
    }

    /**
     * Sin heading visible: la NC embebida ya tiene su propio header grande
     * con el número y los datos del emisor — un heading de Filament arriba
     * sería redundante y rompe la percepción de "documento sobre el
     * escritorio".
     */
    public function getHeading(): string
    {
        return '';
    }

    /**
     * Sin breadcrumbs: la página de impresión es una vista terminal. Desde
     * acá solo se imprime o se vuelve al listado. El botón "Volver" del
     * header cumple esa necesidad — los breadcrumbs solo agregaban ruido.
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * Único action en el header: regreso al listado de NC.
     *
     * La blade de la NC ya expone su propio botón "Imprimir / Guardar como
     * PDF" dentro del iframe — duplicarlo acá sería ruido visual sin valor
     * funcional. Mismo criterio que en PrintInvoice y PrintCashSession.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Volver al listado')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(CreditNoteResource::getUrl('index')),
        ];
    }
}

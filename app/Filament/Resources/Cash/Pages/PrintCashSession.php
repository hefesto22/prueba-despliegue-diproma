<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cash\Pages;

use App\Filament\Resources\Cash\CashSessionResource;
use App\Models\CashSession;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;

/**
 * Página Filament que embebe la hoja de cierre / corte parcial dentro del
 * panel admin (sidebar y navbar visibles) en un iframe.
 *
 * El iframe carga la misma ruta web `cash-sessions.print` — eso aísla el CSS
 * autocontenido del recibo (que asume `html, body` propios) del CSS de
 * Filament, evitando choques. Además permite que el botón "Imprimir" llame
 * a `contentWindow.print()` y se imprima SOLO el recibo, no el chrome del
 * panel.
 *
 * Autorización doble:
 *   1. `canAccess()` estático — filtrado de navegación (la página no aparece
 *      en el sidebar).
 *   2. `Gate::authorize('view', ...)` en el mount — blinda el acceso directo
 *      por URL aunque el usuario conozca el ID.
 *
 * La ruta web subyacente (`cash-sessions.print`) también valida policy@view
 * en su controller — defense in depth: si alguien abre el iframe con un hash
 * falso de sesión ajena, el controller igual responde 403.
 */
class PrintCashSession extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CashSessionResource::class;

    protected string $view = 'filament.resources.cash.pages.print-cash-session';

    /**
     * No aparece en navegación — solo se llega vía action "Imprimir cierre"
     * desde la tabla o desde ViewCashSession.
     */
    protected static bool $shouldRegisterNavigation = false;

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);

        // Blinda acceso directo por URL: aunque la tabla oculte sesiones de
        // otros establishments (via Policy scope), nada impide que el usuario
        // escriba el ID en la URL. Gate explícito acá bloquea eso.
        Gate::authorize('view', $this->record);
    }

    /**
     * Title del <title> HTML de la pestaña — único lugar donde sigue
     * apareciendo el identificador de la sesión, para que el usuario
     * distinga la pestaña si tiene varias abiertas.
     */
    public function getTitle(): string
    {
        /** @var CashSession $record */
        $record = $this->record;

        return $record->isOpen()
            ? "Corte parcial — Sesión #{$record->id}"
            : "Cierre de caja — Sesión #{$record->id}";
    }

    /**
     * Sin heading visible: el recibo embebido ya tiene su propio título
     * grande ("CIERRE DE CAJA · Sesión #N") en el header del documento, así
     * que un heading de Filament arriba sería redundante y rompe la
     * percepción de "documento sobre el escritorio".
     */
    public function getHeading(): string
    {
        return '';
    }

    /**
     * Sin breadcrumbs: la página de impresión es una vista terminal — desde
     * acá solo se imprime o se vuelve. El botón "Volver" del header cumple
     * ambas necesidades de navegación, así que los breadcrumbs solo
     * agregaban ruido visual sin beneficio funcional.
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * Único action en el header: regreso a la vista de la sesión.
     *
     * Antes había un botón "Imprimir cierre" acá también, pero la blade del
     * recibo ya expone su propio botón "Imprimir / Guardar como PDF" dentro
     * del iframe — duplicar la acción sumaba ruido sin agregar valor.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Volver')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(CashSessionResource::getUrl('view', ['record' => $this->record])),
        ];
    }
}

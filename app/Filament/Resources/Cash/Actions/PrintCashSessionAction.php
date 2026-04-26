<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cash\Actions;

use App\Filament\Resources\Cash\CashSessionResource;
use App\Models\CashSession;
use Filament\Actions\Action;

/**
 * Action "Imprimir cierre" — navega a la página interna del panel que
 * embebe la hoja de cierre dentro del layout admin (sidebar/navbar visibles).
 *
 * Se usa tanto como row action en CashSessionsTable como header action en
 * ViewCashSession. Action puramente declarativa (URL) — la impresión real
 * ocurre dentro de la PrintCashSession page, donde un iframe carga la ruta
 * web `cash-sessions.print` y el botón "Imprimir" del header dispara
 * `window.print()` sobre el iframe.
 *
 * Decisión de diseño: antes esto abría nueva pestaña con la URL pública
 * imprimible. Mauricio pidió mantener el chrome del panel visible para no
 * perder contexto de navegación — por eso ahora navega a una página
 * Filament dedicada en vez de la URL standalone.
 *
 * Autorización: la Policy CashSessionPolicy@view se evalúa tanto en el mount
 * de la PrintCashSession page como en el CashSessionPrintController que
 * sirve el iframe — defense in depth.
 */
final class PrintCashSessionAction
{
    public static function make(): Action
    {
        return Action::make('print')
            ->label(fn (CashSession $record): string => $record->isOpen()
                ? 'Imprimir corte parcial'
                : 'Imprimir cierre'
            )
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->url(fn (CashSession $record): string => CashSessionResource::getUrl('print', ['record' => $record]));
    }
}

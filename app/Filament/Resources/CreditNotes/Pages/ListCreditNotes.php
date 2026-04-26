<?php

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Listado de Notas de Crédito. Sin botón "Crear": las NC se emiten desde
 * ViewInvoice (F5b), nunca desde este listado.
 */
class ListCreditNotes extends ListRecords
{
    protected static string $resource = CreditNoteResource::class;

    /**
     * No hay header actions aún. En F5b podríamos agregar un atajo "Ir a
     * facturas" para facilitar el flujo de emisión, pero YAGNI — el panel
     * lateral ya hace esa navegación.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}

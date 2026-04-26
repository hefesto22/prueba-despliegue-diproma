<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    /**
     * No hay botón "Crear" porque las facturas se generan desde el POS.
     */
    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('pos')
                ->label('Ir al POS')
                ->icon('heroicon-o-shopping-bag')
                ->url(route('filament.admin.pages.pos'))
                ->color('primary'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use Filament\Resources\Pages\ListRecords;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    /**
     * No hay botón "Crear" porque las ventas se crean desde el POS.
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

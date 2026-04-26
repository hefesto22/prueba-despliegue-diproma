<?php

namespace App\Filament\Resources\InventoryMovements\Pages;

use App\Exports\KardexExport;
use App\Filament\Resources\InventoryMovements\InventoryMovementResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListInventoryMovements extends ListRecords
{
    protected static string $resource = InventoryMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Ajuste Manual'),

            Action::make('export')
                ->label('Exportar Excel')
                ->icon('heroicon-m-document-arrow-down')
                ->color('success')
                ->action(function () {
                    $filename = 'kardex-global-' . now()->format('Ymd-His') . '.xlsx';

                    return Excel::download(
                        new KardexExport(
                            baseQuery: $this->getFilteredTableQuery(),
                            titleSuffix: 'Global',
                        ),
                        $filename,
                    );
                }),
        ];
    }
}

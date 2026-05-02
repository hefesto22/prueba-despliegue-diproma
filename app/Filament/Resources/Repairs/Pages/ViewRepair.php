<?php

namespace App\Filament\Resources\Repairs\Pages;

use App\Filament\Resources\Repairs\Actions\RepairTransitionActions;
use App\Filament\Resources\Repairs\RepairResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRepair extends ViewRecord
{
    protected static string $resource = RepairResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print_quotation')
                ->label('Imprimir cotización')
                ->icon('heroicon-o-printer')
                ->color('info')
                ->openUrlInNewTab()
                ->url(fn () => route('repairs.quotation.print', ['repair' => $this->record->qr_token])),

            ...RepairTransitionActions::primary(),

            ActionGroup::make(RepairTransitionActions::secondary())
                ->label('Más')
                ->icon('heroicon-o-ellipsis-vertical')
                ->color('gray')
                ->button(),

            EditAction::make(),
        ];
    }
}

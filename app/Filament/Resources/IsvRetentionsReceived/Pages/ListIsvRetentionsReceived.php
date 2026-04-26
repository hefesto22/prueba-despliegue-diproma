<?php

namespace App\Filament\Resources\IsvRetentionsReceived\Pages;

use App\Filament\Resources\IsvRetentionsReceived\IsvRetentionReceivedResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIsvRetentionsReceived extends ListRecords
{
    protected static string $resource = IsvRetentionReceivedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Registrar retención'),
        ];
    }
}

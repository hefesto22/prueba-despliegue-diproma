<?php

namespace App\Filament\Resources\IsvRetentionsReceived\Pages;

use App\Filament\Resources\IsvRetentionsReceived\IsvRetentionReceivedResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;

class ViewIsvRetentionReceived extends ViewRecord
{
    protected static string $resource = IsvRetentionReceivedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}

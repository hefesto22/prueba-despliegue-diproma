<?php

namespace App\Filament\Resources\IsvRetentionsReceived\Pages;

use App\Filament\Resources\IsvRetentionsReceived\IsvRetentionReceivedResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditIsvRetentionReceived extends EditRecord
{
    protected static string $resource = IsvRetentionReceivedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    /**
     * Regreso al View tras editar — igual criterio que en Create:
     * el contador suele verificar el cambio antes de volver al listado.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

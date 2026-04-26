<?php

namespace App\Filament\Resources\IsvRetentionsReceived\Pages;

use App\Filament\Resources\IsvRetentionsReceived\IsvRetentionReceivedResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIsvRetentionReceived extends CreateRecord
{
    protected static string $resource = IsvRetentionReceivedResource::class;

    /**
     * Tras crear la retención, redirijo al View del registro en vez del
     * listado: el contador normalmente quiere verificar que la constancia
     * quedó bien adjunta y ver la casilla SIISAR sugerida antes de
     * continuar con la siguiente.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

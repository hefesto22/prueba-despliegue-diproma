<?php

namespace App\Filament\Resources\SpecOptionResource\Pages;

use App\Filament\Resources\SpecOptionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSpecOption extends EditRecord
{
    protected static string $resource = SpecOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

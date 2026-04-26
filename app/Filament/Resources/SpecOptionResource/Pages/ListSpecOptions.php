<?php

namespace App\Filament\Resources\SpecOptionResource\Pages;

use App\Filament\Resources\SpecOptionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSpecOptions extends ListRecords
{
    protected static string $resource = SpecOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

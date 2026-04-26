<?php

namespace App\Filament\Resources\CaiRanges\Pages;

use App\Filament\Resources\CaiRanges\CaiRangeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCaiRanges extends ListRecords
{
    protected static string $resource = CaiRangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo CAI'),
        ];
    }
}

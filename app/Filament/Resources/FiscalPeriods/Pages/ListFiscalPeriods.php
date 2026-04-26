<?php

namespace App\Filament\Resources\FiscalPeriods\Pages;

use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use Filament\Resources\Pages\ListRecords;

class ListFiscalPeriods extends ListRecords
{
    protected static string $resource = FiscalPeriodResource::class;

    // No CreateAction — los períodos se lazy-crean en FiscalPeriodService.
    protected function getHeaderActions(): array
    {
        return [];
    }
}

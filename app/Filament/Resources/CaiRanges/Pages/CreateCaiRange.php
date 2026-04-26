<?php

namespace App\Filament\Resources\CaiRanges\Pages;

use App\Filament\Resources\CaiRanges\CaiRangeResource;
use App\Models\CaiRange;
use Filament\Resources\Pages\CreateRecord;

class CreateCaiRange extends CreateRecord
{
    protected static string $resource = CaiRangeResource::class;

    protected function afterCreate(): void
    {
        $record = $this->record;

        // Si se marcó como activo, desactivar los demás
        if ($record->is_active) {
            $record->activate();
        }

        // Inicializar current_number si no se llenó
        if ($record->current_number <= 0) {
            $record->update(['current_number' => $record->range_start - 1]);
        }
    }
}

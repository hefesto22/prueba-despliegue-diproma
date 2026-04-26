<?php

namespace App\Filament\Resources\CaiRanges\Pages;

use App\Filament\Resources\CaiRanges\CaiRangeResource;
use Filament\Resources\Pages\EditRecord;

class EditCaiRange extends EditRecord
{
    protected static string $resource = CaiRangeResource::class;

    protected function afterSave(): void
    {
        // Si se activó, desactivar los demás
        if ($this->record->is_active) {
            $this->record->activate();
        }
    }
}

<?php

namespace App\Filament\Resources\Repairs\Pages;

use App\Filament\Resources\Repairs\Actions\RepairTransitionActions;
use App\Filament\Resources\Repairs\RepairResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditRepair extends EditRecord
{
    protected static string $resource = RepairResource::class;

    /**
     * Header actions con acción primaria contextual.
     *
     * Las acciones de transición se desparraman como botones individuales —
     * cada una tiene su `visible()` y solo aparece la que aplica al estado
     * actual. Esto da feedback visual inmediato de "qué sigue" sin requerir
     * que el usuario abra un menú.
     *
     * Anular queda en un dropdown "más opciones" porque es una acción
     * excepcional/administrativa y queremos evitar clicks accidentales.
     */
    protected function getHeaderActions(): array
    {
        return [
            // Imprimir cotización (siempre visible)
            Action::make('print_quotation')
                ->label('Imprimir cotización')
                ->icon('heroicon-o-printer')
                ->color('info')
                ->openUrlInNewTab()
                ->url(fn () => route('repairs.quotation.print', ['repair' => $this->record->qr_token])),

            // Acciones primarias del flujo — botones directos, contextuales por estado
            ...RepairTransitionActions::primary(),

            // Más opciones (Anular) — dropdown secundario
            ActionGroup::make(RepairTransitionActions::secondary())
                ->label('Más')
                ->icon('heroicon-o-ellipsis-vertical')
                ->color('gray')
                ->button(),

            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

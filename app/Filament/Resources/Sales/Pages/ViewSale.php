<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use App\Services\Sales\SaleService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    /**
     * SaleService inyectado via boot() — Livewire 3 soporta method injection
     * en boot(). Propiedad protected para que NO se serialice entre requests.
     */
    protected SaleService $sales;

    public function boot(SaleService $sales): void
    {
        $this->sales = $sales;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label('Anular Venta')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('¿Anular esta venta?')
                ->modalDescription('Se devolverá el stock de todos los productos. Esta acción no se puede revertir.')
                ->modalSubmitActionLabel('Sí, anular')
                ->visible(fn () => $this->record->status->canCancel())
                ->action(function () {
                    try {
                        $this->sales->cancel($this->record);

                        Notification::make()
                            ->title('Venta anulada')
                            ->body("Venta {$this->record->sale_number} anulada. Stock devuelto.")
                            ->success()
                            ->send();

                        $this->refreshFormData(['status']);
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Error')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}

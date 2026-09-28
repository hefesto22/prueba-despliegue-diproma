<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Enums\SupplierDocumentType;
use App\Filament\Resources\Purchases\Actions\ConfirmPurchaseAction;
use App\Filament\Resources\Purchases\Pages\Concerns\ResolvesPurchaseDocument;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Purchase;
use App\Services\Purchases\InternalReceiptNumberGenerator;
use App\Services\Purchases\PurchaseService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class EditPurchase extends EditRecord
{
    use ResolvesPurchaseDocument;

    protected static string $resource = PurchaseResource::class;

    /**
     * Livewire 3 soporta method injection en boot(); el container resuelve
     * el servicio fresco en cada request.
     */
    public function boot(InternalReceiptNumberGenerator $internalReceiptGenerator): void
    {
        $this->internalReceiptGenerator = $internalReceiptGenerator;
    }

    protected function getHeaderActions(): array
    {
        return [
            ConfirmPurchaseAction::make(),

            // Esta página solo abre compras en Borrador (ver authorizeAccess), así
            // que anular aquí es descartar un borrador: no hay consecuencias
            // contables ni de inventario.
            Action::make('cancel')
                ->label('Anular compra')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('¿Descartar este borrador?')
                ->modalDescription(new HtmlString(
                    '<div class="space-y-3 text-sm">'
                    .'<p>Este borrador todavía no entró al Libro de Compras.</p>'
                    .'<p>Al anularlo queda registrado como Anulado en el historial, sin consecuencias contables. Si necesitás corregir algún dato, mejor seguí editando en lugar de anular.</p>'
                    .'</div>'
                ))
                ->modalSubmitActionLabel('Sí, descartar borrador')
                ->modalCancelActionLabel('Seguir editando')
                ->visible(fn (Purchase $record) => $record->status->canCancel())
                ->action(function (Purchase $record, PurchaseService $purchases) {
                    try {
                        $purchases->cancel($record);

                        Notification::make()
                            ->warning()
                            ->title('Borrador descartado')
                            ->send();

                        $this->redirect($this->getResource()::getUrl('view', ['record' => $record]));
                    } catch (\Exception $e) {
                        Notification::make()
                            ->danger()
                            ->title('Error al anular')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),

            ViewAction::make(),
            DeleteAction::make()
                ->visible(fn () => $this->record->isEditable()),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Guardar dentro de una transacción (misma razón que CreatePurchase: el
     * correlativo del RI necesita lock + generación + UPDATE atómicos).
     *
     * El correlativo RI se regenera solo si:
     *   - la compra pasa de Factura a RI, o
     *   - ya era RI pero cambió la fecha (el segmento YYYYMMDD debe reflejar
     *     la fecha del documento).
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Purchase $record */
        return DB::transaction(function () use ($record, $data) {
            $needsNewNumber = SupplierDocumentType::isReciboInterno($data['document_type'] ?? null)
                && ($record->document_type !== SupplierDocumentType::ReciboInterno || $this->dateChanged($record, $data));

            $record->fill($this->resolveDocumentFields($data, $needsNewNumber));
            $record->save();

            return $record;
        });
    }

    private function dateChanged(Purchase $record, array $data): bool
    {
        if (! isset($data['date'])) {
            return false;
        }

        return ! $record->date->isSameDay(Carbon::parse($data['date']));
    }

    /**
     * Solo se puede editar en estado borrador.
     */
    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        if (! $this->record->isEditable()) {
            Notification::make()
                ->warning()
                ->title('No editable')
                ->body('Solo se pueden editar compras en estado borrador.')
                ->send();

            $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
        }
    }
}

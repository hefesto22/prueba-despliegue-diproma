<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\Pages\Concerns\ResolvesPurchaseDocument;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Purchase;
use App\Services\Purchases\InternalReceiptNumberGenerator;
use App\Services\Purchases\PurchaseService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreatePurchase extends CreateRecord
{
    use ResolvesPurchaseDocument;

    protected static string $resource = PurchaseResource::class;

    /**
     * "Guardar y confirmar" en curso. Vive solo durante el request que lo
     * dispara (protected: Livewire no lo serializa).
     */
    protected bool $confirmAfterCreate = false;

    /**
     * Si la confirmación falló, por qué: la compra quedó como borrador.
     */
    protected ?string $confirmationError = null;

    /**
     * Livewire 3 soporta method injection en boot(); el container resuelve
     * el servicio fresco en cada request.
     */
    public function boot(InternalReceiptNumberGenerator $internalReceiptGenerator): void
    {
        $this->internalReceiptGenerator = $internalReceiptGenerator;
    }

    /**
     * "Guardar y confirmar" es la acción principal: un gasto del día se
     * registra y se paga de un solo clic. "Guardar borrador" queda para
     * documentos que alguien más revisa antes de confirmar.
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateAndConfirmFormAction(),
            $this->getCreateFormAction()
                ->label('Guardar borrador')
                ->color('gray'),
            $this->getCancelFormAction(),
        ];
    }

    protected function getCreateAndConfirmFormAction(): Action
    {
        return Action::make('createAndConfirm')
            ->label('Guardar y confirmar')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->action('createAndConfirm')
            // Confirmar exige Update:Purchase (mismo permiso que la acción
            // "Confirmar compra" de la vista).
            ->visible(fn (): bool => Gate::allows('update', new Purchase));
    }

    public function createAndConfirm(): void
    {
        $this->confirmAfterCreate = true;

        $this->create();
    }

    /**
     * Si confirmar falla (p. ej. el período fiscal ya fue declarado) la
     * compra NO se pierde: queda como borrador y el aviso explica por qué.
     * Confirmar corre en su propia transacción dentro de PurchaseService.
     */
    protected function afterCreate(): void
    {
        if (! $this->confirmAfterCreate || ! Gate::allows('update', $this->record)) {
            return;
        }

        try {
            app(PurchaseService::class)->confirm($this->record);
        } catch (\Exception $e) {
            $this->confirmationError = $e->getMessage();
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        if (! $this->confirmAfterCreate) {
            return Notification::make()
                ->success()
                ->title('Borrador guardado');
        }

        if ($this->confirmationError !== null) {
            return Notification::make()
                ->warning()
                ->title('Se guardó como borrador, sin confirmar')
                ->body($this->confirmationError)
                ->persistent();
        }

        return Notification::make()
            ->success()
            ->title('Compra registrada y confirmada');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Crear la compra dentro de una transacción.
     *
     * En Recibo Interno el correlativo se genera con lockForUpdate sobre el
     * proveedor genérico; ese lock debe vivir hasta el COMMIT del INSERT para
     * que dos requests concurrentes no reciban el mismo NNNN diario, y si el
     * INSERT falla el número no queda "quemado". Para Factura la transacción
     * es transparente.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(fn () => static::getModel()::create(
            $this->resolveDocumentFields($data, generateInternalReceiptNumber: true),
        ));
    }
}

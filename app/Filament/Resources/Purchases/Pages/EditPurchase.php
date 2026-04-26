<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Purchases\InternalReceiptNumberGenerator;
use App\Services\Purchases\PurchaseService;
use App\Services\Purchases\PurchaseTotalsCalculator;
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
    protected static string $resource = PurchaseResource::class;

    /**
     * Servicios inyectados via boot() — Livewire 3 soporta method injection
     * en boot(). Propiedades protected para que NO se serialicen entre requests
     * (solo las public lo hacen) — el container resuelve fresh cada render.
     */
    protected PurchaseService $purchases;

    protected InternalReceiptNumberGenerator $internalReceiptGenerator;

    protected PurchaseTotalsCalculator $totalsCalculator;

    public function boot(
        PurchaseService $purchases,
        InternalReceiptNumberGenerator $internalReceiptGenerator,
        PurchaseTotalsCalculator $totalsCalculator,
    ): void {
        $this->purchases = $purchases;
        $this->internalReceiptGenerator = $internalReceiptGenerator;
        $this->totalsCalculator = $totalsCalculator;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Confirmar desde EditPurchase: mismo modal informativo que ViewPurchase.
            // Mantener ambos sincronizados — la responsabilidad de la advertencia
            // sobre el costo promedio no debe depender de desde dónde se confirme.
            Action::make('confirm')
                ->label('Confirmar compra')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('¿Confirmar la compra?')
                ->modalDescription(new HtmlString(
                    '<div class="space-y-3 text-sm">'
                    .'<p>Al confirmar, esta compra deja de ser un borrador editable y pasa a ser una operación contable real:</p>'
                    .'<ul class="list-disc list-inside space-y-1">'
                    .'<li><strong>El stock de cada producto se incrementa</strong> con la cantidad comprada.</li>'
                    .'<li><strong>El costo promedio ponderado se recalcula</strong> mezclando esta compra con el inventario existente.</li>'
                    .'<li>Si es contado, queda <strong>marcada como Pagada</strong> en la misma transacción.</li>'
                    .'<li>Entra al <strong>Libro de Compras SAR</strong> (excepto Recibos Internos).</li>'
                    .'</ul>'
                    .'<div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 p-3 text-amber-900 dark:text-amber-200">'
                    .'<strong>Importante:</strong> después de confirmar, si descubre un error y anula la compra, el stock vuelve pero <strong>el costo promedio del producto NO se revierte automáticamente</strong>. Verifique cantidades, costos y proveedor antes de confirmar.'
                    .'</div>'
                    .'</div>'
                ))
                ->modalSubmitActionLabel('Sí, confirmar compra')
                ->modalCancelActionLabel('Volver al borrador')
                ->visible(fn () => $this->record->status->canConfirm())
                ->action(function () {
                    try {
                        $this->purchases->confirm($this->record);

                        Notification::make()
                            ->success()
                            ->title('Compra confirmada')
                            ->body('El stock y los costos se actualizaron correctamente.')
                            ->send();

                        $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                    } catch (\Exception $e) {
                        Notification::make()
                            ->danger()
                            ->title('Error al confirmar')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),

            // Anular desde EditPurchase: como esta página solo es accesible cuando
            // la compra es Borrador (authorizeAccess redirige si no), la operación
            // es trivial — no hay consecuencias contables. Mensaje breve sin las
            // advertencias graves del modal de ViewPurchase para Confirmadas.
            Action::make('cancel')
                ->label('Anular compra')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('¿Descartar este borrador?')
                ->modalDescription(new HtmlString(
                    '<div class="space-y-3 text-sm">'
                    .'<p>Este borrador todavía no afectó el inventario ni el costo de los productos.</p>'
                    .'<p>Al anular simplemente se descarta — la compra queda registrada como Anulada en el historial pero sin consecuencias contables. Si necesitás corregir algún dato, mejor seguí editando en lugar de anular.</p>'
                    .'</div>'
                ))
                ->modalSubmitActionLabel('Sí, descartar borrador')
                ->modalCancelActionLabel('Seguir editando')
                ->visible(fn () => $this->record->status->canCancel())
                ->action(function () {
                    try {
                        $this->purchases->cancel($this->record);

                        Notification::make()
                            ->warning()
                            ->title('Borrador descartado')
                            ->send();

                        $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
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
     * Override del update para envolver el save en una transacción cuando el
     * Purchase edit cambió document_type a/desde Recibo Interno. Misma razón
     * que CreatePurchase::handleRecordCreation — el correlativo del RI requiere
     * lock + generación + update atómicos. Para ediciones sin cambio de tipo,
     * la transacción es transparente (solo garantiza consistencia sin costo).
     *
     * Regeneramos el correlativo RI solo si:
     *   - Antes NO era RI y ahora SÍ lo es (cambio Factura→RI en borrador).
     *   - Ya era RI pero cambió la fecha (el segmento YYYYMMDD del correlativo
     *     debe reflejar la fecha de emisión). Casos 1-a-1 raros pero correctos.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Purchase $record */
        return DB::transaction(function () use ($record, $data) {
            $currentIsRi = $record->document_type === SupplierDocumentType::ReciboInterno;
            $nextIsRi = $this->isReciboInterno($data['document_type'] ?? null);

            if ($nextIsRi) {
                $needsNewNumber = ! $currentIsRi
                    || $this->dateChanged($record, $data);

                $data = $this->resolveReciboInternoFields($data, $needsNewNumber);
            }

            $record->fill($data);
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
     * Defense in depth: aun cuando el form deshabilita/oculta estos campos en
     * modo RI, los reescribimos acá para blindar el payload ante manipulación.
     */
    private function resolveReciboInternoFields(array $data, bool $generateNumber): array
    {
        $data['supplier_id'] = Supplier::forInternalReceipts()->id;
        $data['supplier_cai'] = null;
        $data['credit_days'] = 0;

        if ($generateNumber) {
            $fecha = isset($data['date']) ? Carbon::parse($data['date']) : Carbon::now();
            $data['supplier_invoice_number'] = $this->internalReceiptGenerator->next($fecha);
        }

        return $data;
    }

    private function isReciboInterno(mixed $documentType): bool
    {
        return $documentType === SupplierDocumentType::ReciboInterno->value
            || $documentType === SupplierDocumentType::ReciboInterno;
    }

    /**
     * Al guardar cambios, recalcular totales (subtotal/taxable/exempt/isv/total)
     * desde los items. Delega al Calculator — fuente única de verdad.
     */
    protected function afterSave(): void
    {
        $this->totalsCalculator->recalculate($this->record);
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

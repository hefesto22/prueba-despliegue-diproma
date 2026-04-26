<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Enums\PurchaseStatus;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Services\Purchases\PurchaseService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;

class ViewPurchase extends ViewRecord
{
    protected static string $resource = PurchaseResource::class;

    /**
     * PurchaseService inyectado via boot() — Livewire 3 soporta method
     * injection. Propiedad protected para que NO se serialice entre requests.
     */
    protected PurchaseService $purchases;

    public function boot(PurchaseService $purchases): void
    {
        $this->purchases = $purchases;
    }

    protected function getHeaderActions(): array
    {
        return [
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

            Action::make('cancel')
                ->label('Anular compra')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                // Encabezado y mensaje del modal varían según el estado:
                //  - Borrador: anular es trivial (no afectó inventario, no tocó CPP).
                //    Mensaje breve, sin advertencias graves.
                //  - Confirmada: anular tiene consecuencias contables permanentes
                //    (stock se reversa pero CPP no). Mensaje severo y responsabilidad
                //    explícita en el botón de submit.
                ->modalHeading(fn () => $this->record->status === PurchaseStatus::Borrador
                    ? '¿Descartar este borrador?'
                    : '¿Anular esta compra confirmada?')
                ->modalDescription(fn () => new HtmlString(
                    $this->record->status === PurchaseStatus::Borrador
                        ? '<div class="space-y-3 text-sm">'
                            .'<p>Este borrador todavía no afectó el inventario ni el costo de los productos.</p>'
                            .'<p>Al anular simplemente se descarta — la compra queda registrada como Anulada en el historial pero sin consecuencias contables. Si querés volver a usar los datos, mejor edítelo antes de anular.</p>'
                            .'</div>'
                        : '<div class="space-y-3 text-sm">'
                            .'<p>Anular una compra confirmada es una operación reservada para casos excepcionales. Esto sucede:</p>'
                            .'<ul class="list-disc list-inside space-y-1">'
                            .'<li>El <strong>stock se reversa</strong>: las unidades que entraron con esta compra salen del inventario.</li>'
                            .'<li>Se registra un <strong>movimiento de salida en kardex</strong> con el costo histórico de la compra.</li>'
                            .'<li>La compra queda como <strong>Anulada</strong> en el listado.</li>'
                            .'</ul>'
                            .'<div class="rounded-lg bg-red-50 dark:bg-red-900/20 p-3 text-red-900 dark:text-red-200">'
                            .'<strong>⚠️ Lo que NO se revierte:</strong>'
                            .'<ul class="list-disc list-inside mt-2 space-y-1">'
                            .'<li>El <strong>costo promedio ponderado del producto</strong> queda con el valor que tomó al confirmarse esta compra. No vuelve al valor previo.</li>'
                            .'<li>El <strong>pago</strong> se preserva como histórico (si era contado, sigue marcada Pagada — el dinero ya se entregó al proveedor).</li>'
                            .'</ul>'
                            .'<p class="mt-2"><strong>Por eso existe el estado Borrador:</strong> es la última oportunidad para detectar errores sin consecuencias. Si encontrás un error, lo correcto es revisarlo antes de confirmar — anular después no deshace todo.</p>'
                            .'</div>'
                            .'<p class="text-xs italic text-gray-500 dark:text-gray-400">Asumo la responsabilidad de las consecuencias contables de esta anulación.</p>'
                            .'</div>'
                ))
                ->modalSubmitActionLabel(fn () => $this->record->status === PurchaseStatus::Borrador
                    ? 'Sí, descartar borrador'
                    : 'Sí, anular y asumir las consecuencias')
                ->modalCancelActionLabel('Volver')
                ->visible(fn () => $this->record->status->canCancel())
                ->action(function () {
                    $eraConfirmada = $this->record->status === PurchaseStatus::Confirmada;

                    try {
                        $this->purchases->cancel($this->record);

                        $notification = Notification::make()
                            ->warning()
                            ->title('Compra anulada');

                        if ($eraConfirmada) {
                            $notification->body('Stock reversado. Recordá que el costo promedio del producto NO se revirtió.');
                        }

                        $notification->send();

                        $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                    } catch (\Exception $e) {
                        Notification::make()
                            ->danger()
                            ->title('Error al anular')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),

            EditAction::make()
                ->visible(fn () => $this->record->isEditable()),
            DeleteAction::make()
                ->visible(fn () => $this->record->isEditable()),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}

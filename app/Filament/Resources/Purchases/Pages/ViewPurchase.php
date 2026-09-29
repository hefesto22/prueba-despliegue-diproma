<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Enums\PurchaseStatus;
use App\Filament\Resources\Purchases\Actions\ConfirmPurchaseAction;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Purchase;
use App\Services\Purchases\PurchaseService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

class ViewPurchase extends ViewRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConfirmPurchaseAction::make(),

            Action::make('cancel')
                ->label('Anular compra')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(fn (Purchase $record) => $record->status === PurchaseStatus::Borrador
                    ? '¿Descartar este borrador?'
                    : '¿Anular esta compra confirmada?')
                ->modalDescription(fn (Purchase $record) => new HtmlString(self::describeCancellation($record)))
                ->modalSubmitActionLabel(fn (Purchase $record) => $record->status === PurchaseStatus::Borrador
                    ? 'Sí, descartar borrador'
                    : 'Sí, anular compra')
                ->modalCancelActionLabel('Volver')
                ->visible(fn (Purchase $record) => $record->status->canCancel())
                ->authorize(fn (Purchase $record): Response => Gate::inspect(self::cancelAbility($record), $record))
                ->action(function (Purchase $record, PurchaseService $purchases) {
                    // Se evalúa ANTES de anular: después el estado ya es Anulada.
                    $revertsStock = $record->status === PurchaseStatus::Confirmada
                        && $record->items()->exists();

                    try {
                        $purchases->cancel($record);
                    } catch (\Exception $e) {
                        Notification::make()
                            ->danger()
                            ->title('Error al anular')
                            ->body($e->getMessage())
                            ->send();

                        return;
                    }

                    $notification = Notification::make()
                        ->warning()
                        ->title('Compra anulada');

                    if ($revertsStock) {
                        $notification->body('Se revirtió el stock de sus productos. El costo promedio de esos productos NO se revirtió.');
                    }

                    $notification->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $record]));
                }),

            EditAction::make()
                ->visible(fn (Purchase $record) => $record->isEditable()),
            DeleteAction::make()
                ->visible(fn (Purchase $record) => $record->isEditable()),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    /**
     * Permiso que exige "Anular" según el estado de la compra:
     *   - Borrador: descartarlo equivale a corregirlo → Update (el cajero
     *     puede descartar el gasto que capturó mal).
     *   - Confirmada: sale del Libro de Compras y puede devolver efectivo a la
     *     caja → Delete (admin), igual que eliminar.
     */
    private static function cancelAbility(Purchase $record): string
    {
        return $record->status === PurchaseStatus::Borrador ? 'update' : 'delete';
    }

    /**
     * Texto del modal de anulación según el estado y el tipo de compra:
     *   - Borrador: descartarlo no tiene consecuencias.
     *   - Confirmada: sale del Libro de Compras.
     *   - Confirmada heredada (con productos): además revierte el stock que
     *     metió, pero no el costo promedio que calculó en su momento.
     */
    private static function describeCancellation(Purchase $record): string
    {
        if ($record->status === PurchaseStatus::Borrador) {
            return '<div class="space-y-3 text-sm">'
                .'<p>Este borrador todavía no entró al Libro de Compras.</p>'
                .'<p>Al anularlo queda registrado como Anulado en el historial, sin consecuencias contables.</p>'
                .'</div>';
        }

        $legacyStock = $record->items()->exists()
            ? '<li>Esta compra se registró con productos (formato anterior): <strong>su stock se revierte</strong> y se registra la salida en el Kardex.</li>'
                .'<li>El <strong>costo promedio</strong> de esos productos <strong>NO se revierte</strong>.</li>'
            : '';

        return '<div class="space-y-3 text-sm">'
            .'<p>Anular una compra confirmada es para casos excepcionales (documento registrado por error o duplicado):</p>'
            .'<ul class="list-disc list-inside space-y-1">'
            .'<li>La compra <strong>sale del Libro de Compras</strong> y su ISV deja de contar como crédito fiscal.</li>'
            .($record->isPaidFromCash()
                ? '<li>Se pagó en <strong>efectivo</strong>: los <strong>L '.number_format((float) $record->total, 2).'</strong> vuelven a la caja abierta de la sucursal (hace falta una caja abierta para anular).</li>'
                : '<li>Si era de contado sigue marcada <strong>Pagada</strong>: el dinero ya se entregó.</li>')
            .$legacyStock
            .'</ul>'
            .'<p class="text-xs italic text-gray-500 dark:text-gray-400">Si el período fiscal ya fue declarado, el sistema no permitirá anularla sin reabrirlo.</p>'
            .'</div>';
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchases\Actions;

use App\Enums\SupplierDocumentType;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Purchase;
use App\Services\Purchases\PurchaseService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Acción "Confirmar compra" — única definición para ViewPurchase y EditPurchase.
 *
 * Antes vivía copiada en ambas páginas con un comentario de "mantener ambos
 * sincronizados"; el texto del modal describía efectos (stock, costo promedio)
 * que desde la Fase 1 ya no ocurren. Una sola clase evita que las copias
 * vuelvan a divergir.
 *
 * `$record` lo inyecta Filament: en páginas de registro las acciones de
 * encabezado reciben el registro de la página (InteractsWithRecord).
 *
 * Autorización: requiere Update:Purchase. El cajero lo tiene porque confirma
 * sus gastos del día (es lo que saca el efectivo del cajón); el contador, que
 * solo audita, no. Sin permiso la acción se oculta.
 */
final class ConfirmPurchaseAction
{
    public static function make(): Action
    {
        return Action::make('confirm')
            ->label('Confirmar compra')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('¿Confirmar la compra?')
            ->modalDescription(fn (Purchase $record) => new HtmlString(self::describeEffects($record)))
            ->modalSubmitActionLabel('Sí, confirmar compra')
            ->modalCancelActionLabel('Volver al borrador')
            ->visible(fn (Purchase $record): bool => $record->status->canConfirm())
            ->authorize('update')
            ->action(function (Purchase $record, PurchaseService $purchases, Action $action): void {
                try {
                    $purchases->confirm($record);
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo confirmar')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Compra confirmada')
                    ->send();

                $action->redirect(PurchaseResource::getUrl('view', ['record' => $record]));
            });
    }

    private static function describeEffects(Purchase $record): string
    {
        $bookLine = $record->document_type === SupplierDocumentType::ReciboInterno
            ? '<li>Es un <strong>Recibo Interno</strong>: queda como control interno y <strong>no entra al Libro de Compras SAR</strong>.</li>'
            : '<li>El documento <strong>entra al Libro de Compras SAR</strong> del mes y su ISV cuenta como crédito fiscal.</li>';

        $cashLine = $record->isPaidFromCash()
            ? '<li>Se paga en <strong>efectivo</strong>: salen <strong>L '.number_format((float) $record->total, 2).'</strong> de la caja abierta de la sucursal (si no hay caja abierta, no se puede confirmar).</li>'
            : '';

        return '<div class="space-y-3 text-sm">'
            .'<p>Al confirmar, esta compra deja de ser un borrador editable:</p>'
            .'<ul class="list-disc list-inside space-y-1">'
            .$bookLine
            .$cashLine
            .'<li>Si es de contado, queda <strong>marcada como Pagada</strong>.</li>'
            .'<li>El inventario <strong>no cambia</strong>: los equipos se ingresan desde Productos.</li>'
            .'</ul>'
            .'<div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 p-3 text-amber-900 dark:text-amber-200">'
            .'Verifique número, CAI y montos contra la factura antes de confirmar: después solo se puede anular.'
            .'</div>'
            .'</div>';
    }
}

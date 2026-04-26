<?php

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Services\CreditNotes\CreditNoteService;
use App\Services\CreditNotes\Exceptions\NotaCreditoYaAnuladaException;
use App\Services\CreditNotes\Exceptions\StockInsuficienteParaAnularNCException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Página de detalle de una Nota de Crédito.
 *
 * Las header actions duplican funcionalidad de la tabla (Imprimir, URL
 * pública, Anular) para que el operador pueda actuar sin volver al listado.
 * El handling de excepciones es idéntico al de la tabla — mantener ambos
 * flujos alineados es deliberado: el Service es la fuente única de verdad,
 * la UI solo traduce excepciones a Notifications.
 */
class ViewCreditNote extends ViewRecord
{
    protected static string $resource = CreditNoteResource::class;

    /**
     * CreditNoteService inyectado via boot() — Livewire 3 soporta method
     * injection. Propiedad protected para que NO se serialice entre requests.
     */
    protected CreditNoteService $creditNotes;

    public function boot(CreditNoteService $creditNotes): void
    {
        $this->creditNotes = $creditNotes;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('imprimir')
                ->label('Imprimir')
                ->icon('heroicon-o-printer')
                ->color('primary')
                // URL interna del panel (PrintCreditNote) — el recibo se
                // embebe en iframe manteniendo sidebar/navbar. Simétrico a
                // Facturas y Sesiones de Caja.
                ->url(fn (): string => CreditNoteResource::getUrl('print', ['record' => $this->record])),

            Action::make('url_publica')
                ->label('Link verificación')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->visible(fn (): bool => ! empty($this->record->integrity_hash))
                ->action(function (): void {
                    Notification::make()
                        ->title('URL de verificación pública')
                        ->body(route('credit-notes.verify', ['hash' => $this->record->integrity_hash]))
                        ->success()
                        ->persistent()
                        ->send();
                }),

            Action::make('anular')
                ->label('Anular')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('¿Anular esta Nota de Crédito?')
                ->modalDescription(
                    'Si la razón fue "Devolución física", el stock del producto '
                    . 'se retirará nuevamente del inventario. Esta acción no se '
                    . 'puede revertir y queda registrada en el kardex.'
                )
                ->modalSubmitActionLabel('Sí, anular')
                ->visible(fn (): bool => ! $this->record->is_void)
                ->action(function (): void {
                    try {
                        $this->creditNotes->voidNotaCredito($this->record);

                        Notification::make()
                            ->title('Nota de Crédito anulada')
                            ->body("La NC {$this->record->credit_note_number} fue anulada correctamente.")
                            ->success()
                            ->send();

                        // Redirigir al mismo View para refrescar el registro
                        // (recarga $this->record con is_void=true → oculta la
                        // acción "Anular" y muestra el estado actualizado).
                        $this->redirect(
                            $this->getResource()::getUrl('view', ['record' => $this->record])
                        );
                    } catch (StockInsuficienteParaAnularNCException $e) {
                        Notification::make()
                            ->title('No se puede anular la NC')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    } catch (NotaCreditoYaAnuladaException $e) {
                        Notification::make()
                            ->title('Nota de Crédito ya anulada')
                            ->body($e->getMessage())
                            ->warning()
                            ->send();
                    }
                }),
        ];
    }
}

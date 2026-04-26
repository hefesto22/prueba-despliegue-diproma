<?php

namespace App\Filament\Resources\CreditNotes\Tables;

use App\Enums\CreditNoteReason;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Models\CreditNote;
use App\Services\CreditNotes\CreditNoteService;
use App\Services\CreditNotes\Exceptions\NotaCreditoYaAnuladaException;
use App\Services\CreditNotes\Exceptions\StockInsuficienteParaAnularNCException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Configuración de la tabla de Notas de Crédito para el panel admin.
 *
 * La acción "anular" envuelve CreditNoteService::voidNotaCredito() con
 * manejo explícito de las excepciones tipadas del dominio:
 *   - StockInsuficienteParaAnularNCException → Notification persistente
 *     con el mensaje del dominio (incluye producto, requerido, disponible).
 *   - NotaCreditoYaAnuladaException → Notification informativa (puede
 *     ocurrir por race condition entre render y click si otro operador
 *     anuló primero).
 *
 * Sin este try/catch Filament muestra una pantalla de error genérica que
 * rompe la experiencia del operador en un flujo operativo normal.
 */
class CreditNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('credit_note_number')
                    ->label('No. NC')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold')
                    ->color(fn (CreditNote $record): string => $record->is_void ? 'danger' : 'warning'),

                TextColumn::make('credit_note_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('original_invoice_number')
                    ->label('Factura origen')
                    ->searchable()
                    ->copyable()
                    ->color('gray'),

                TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->searchable()
                    ->limit(30),

                TextColumn::make('customer_rtn')
                    ->label('RTN')
                    ->searchable()
                    ->placeholder('Consumidor Final')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reason')
                    ->label('Razón')
                    ->badge(),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->alignEnd(),

                TextColumn::make('isv')
                    ->label('ISV')
                    ->money('HNL')
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Estado derivado de is_void:
                //   is_void=true  → anulada → X roja
                //   is_void=false → válida  → check verde
                // Sin getStateUsing intermedio: la doble negación previa
                // mostraba el icono invertido. Simétrico a InvoicesTable.
                IconColumn::make('is_void')
                    ->label('Estado')
                    ->boolean()
                    ->trueIcon('heroicon-o-x-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->tooltip(fn (CreditNote $record): string => $record->is_void ? 'Anulada' : 'Válida'),

                TextColumn::make('creator.name')
                    ->label('Creador')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->options([
                        'valid' => 'Válidas',
                        'void'  => 'Anuladas',
                    ])
                    ->query(function ($query, array $data) {
                        return match ($data['value']) {
                            'valid' => $query->where('is_void', false),
                            'void'  => $query->where('is_void', true),
                            default => $query,
                        };
                    }),

                SelectFilter::make('reason')
                    ->label('Razón')
                    ->options(collect(CreditNoteReason::cases())
                        ->mapWithKeys(fn (CreditNoteReason $r) => [$r->value => $r->getLabel()])
                        ->all()),

                Filter::make('credit_note_date')
                    ->label('Fecha')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label('Desde'),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label('Hasta'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('credit_note_date', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('credit_note_date', '<=', $date));
                    }),
            ])
            ->actions([
                ViewAction::make()
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->color('primary'),

                Action::make('imprimir')
                    ->label('Imprimir')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    // URL interna del panel (PrintCreditNote) — el recibo se
                    // embebe en iframe manteniendo sidebar/navbar, simétrico
                    // a Facturas y Sesiones de Caja. El botón "Imprimir"
                    // vive dentro del propio iframe.
                    ->url(fn (CreditNote $record): string => CreditNoteResource::getUrl('print', ['record' => $record])),

                Action::make('url_publica')
                    ->label('Copiar link verificación')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->visible(fn (CreditNote $record): bool => ! empty($record->integrity_hash))
                    ->action(function (CreditNote $record): void {
                        // No podemos escribir al clipboard desde PHP; mostramos la
                        // URL en una notificación persistente para copiar manual.
                        // Mismo patrón que en InvoicesTable::url_publica.
                        Notification::make()
                            ->title('URL de verificación pública')
                            ->body(route('credit-notes.verify', ['hash' => $record->integrity_hash]))
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
                    ->visible(fn (CreditNote $record): bool => ! $record->is_void)
                    ->action(function (CreditNote $record, CreditNoteService $creditNotes): void {
                        try {
                            $creditNotes->voidNotaCredito($record);

                            Notification::make()
                                ->title('Nota de Crédito anulada')
                                ->body("La NC {$record->credit_note_number} fue anulada correctamente.")
                                ->success()
                                ->send();
                        } catch (StockInsuficienteParaAnularNCException $e) {
                            // Stock insuficiente: mensaje persistente porque el
                            // usuario necesita leer detalles (producto, cantidades)
                            // antes de decidir la siguiente acción contable.
                            Notification::make()
                                ->title('No se puede anular la NC')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        } catch (NotaCreditoYaAnuladaException $e) {
                            // Race condition: otro operador anuló entre el render y
                            // el click. Mensaje informativo y refresco implícito
                            // (Filament vuelve a consultar el registro tras la acción).
                            Notification::make()
                                ->title('Nota de Crédito ya anulada')
                                ->body($e->getMessage())
                                ->warning()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('credit_note_date', 'desc')
            ->striped()
            ->paginated([10, 25, 50]);
    }
}

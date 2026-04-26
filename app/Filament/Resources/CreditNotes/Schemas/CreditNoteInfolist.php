<?php

namespace App\Filament\Resources\CreditNotes\Schemas;

use App\Models\CreditNote;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Infolist administrativo de la Nota de Crédito.
 *
 * Distinto al print view público (resources/views/credit-notes/show.blade.php):
 *   - El print view es el documento fiscal orientado al cliente (QR,
 *     tipografía de factura, watermark ANULADA, diseño imprimible).
 *   - Este infolist es la lente administrativa — quién creó, cuándo se
 *     emitió/anuló, estado del hash de integridad, trazabilidad, links a
 *     factura origen y URL de verificación.
 *
 * Decisión de diseño: secciones `aside()` para mantener el patrón visual
 * de PurchaseInfolist. Grids explícitos (no responsive auto-layout) para
 * control fino del layout en pantallas de tamaño típico del operador.
 */
class CreditNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // ─── Documento fiscal ─────────────────────────────
                Section::make('Documento fiscal')
                    ->aside()
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('credit_note_number')
                                ->label('No. NC')
                                ->weight('bold')
                                ->copyable()
                                ->color(fn (CreditNote $record): string => $record->is_void ? 'danger' : 'warning'),

                            TextEntry::make('credit_note_date')
                                ->label('Fecha emisión')
                                ->date('d/m/Y'),

                            TextEntry::make('cai')
                                ->label('CAI')
                                ->copyable(),

                            TextEntry::make('cai_expiration_date')
                                ->label('Vencimiento CAI')
                                ->date('d/m/Y'),
                        ]),

                        Grid::make(3)->schema([
                            TextEntry::make('emission_point')
                                ->label('Punto emisión')
                                ->placeholder('N/A'),

                            TextEntry::make('establishment.code')
                                ->label('Establecimiento')
                                ->placeholder('N/A'),

                            IconEntry::make('is_void')
                                ->label('Estado')
                                ->boolean()
                                ->trueIcon('heroicon-o-x-circle')
                                ->falseIcon('heroicon-o-check-circle')
                                ->trueColor('danger')
                                ->falseColor('success')
                                ->getStateUsing(fn (CreditNote $record): bool => ! $record->is_void)
                                ->tooltip(fn (CreditNote $record): string => $record->is_void ? 'Anulada' : 'Válida'),
                        ]),
                    ]),

                // ─── Factura que acredita ─────────────────────────
                // Snapshot al momento de emisión — NO re-consulta la factura
                // actual. Si la factura origen fue modificada después (no
                // debería pasar por el trait LocksFiscalFieldsAfterEmission)
                // estos campos quedan fieles a lo que firmó la NC.
                Section::make('Factura que acredita')
                    ->aside()
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('original_invoice_number')
                                ->label('No. Factura origen')
                                ->weight('bold')
                                ->copyable()
                                ->url(fn (CreditNote $record): ?string => $record->invoice_id
                                    ? route('filament.admin.resources.invoices.index', ['tableSearch' => $record->original_invoice_number])
                                    : null),

                            TextEntry::make('original_invoice_date')
                                ->label('Fecha factura')
                                ->date('d/m/Y'),

                            TextEntry::make('original_invoice_cai')
                                ->label('CAI factura')
                                ->copyable()
                                ->placeholder('—'),
                        ]),
                    ]),

                // ─── Receptor (snapshot) ──────────────────────────
                Section::make('Receptor')
                    ->aside()
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('customer_name')
                                ->label('Cliente')
                                ->weight('bold'),

                            TextEntry::make('customer_rtn')
                                ->label('RTN')
                                ->placeholder('Consumidor Final')
                                ->copyable(),
                        ]),
                    ]),

                // ─── Razón de emisión ─────────────────────────────
                Section::make('Razón de emisión')
                    ->aside()
                    ->schema([
                        TextEntry::make('reason')
                            ->label('Razón')
                            ->badge(),

                        TextEntry::make('reason_notes')
                            ->label('Notas explicativas')
                            ->placeholder('Sin notas')
                            ->visible(fn (CreditNote $record): bool => filled($record->reason_notes)),
                    ]),

                // ─── Ítems acreditados ────────────────────────────
                Section::make('Ítems acreditados')
                    ->aside()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                Grid::make(6)->schema([
                                    TextEntry::make('product.name')
                                        ->label('Producto')
                                        ->columnSpan(2),

                                    TextEntry::make('quantity')
                                        ->label('Cantidad'),

                                    TextEntry::make('unit_price')
                                        ->label('Precio c/u')
                                        ->money('HNL'),

                                    TextEntry::make('subtotal')
                                        ->label('Subtotal')
                                        ->money('HNL'),

                                    TextEntry::make('total')
                                        ->label('Total')
                                        ->money('HNL')
                                        ->weight('bold'),
                                ]),
                            ]),
                    ]),

                // ─── Totales ──────────────────────────────────────
                Section::make('Totales')
                    ->aside()
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('taxable_total')
                                ->label('Gravado')
                                ->money('HNL'),

                            TextEntry::make('exempt_total')
                                ->label('Exento')
                                ->money('HNL'),

                            TextEntry::make('isv')
                                ->label('ISV')
                                ->money('HNL')
                                ->color('warning'),

                            TextEntry::make('total')
                                ->label('Total')
                                ->money('HNL')
                                ->weight('bold')
                                ->size('lg'),
                        ]),
                    ]),

                // ─── Integridad & auditoría ───────────────────────
                // Bloque técnico para operadores: hash sellado, fechas y
                // creador. Permite auditar la NC sin ir al print view.
                Section::make('Integridad y auditoría')
                    ->aside()
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('integrity_hash')
                                ->label('Hash de integridad (SHA-256)')
                                ->copyable()
                                ->placeholder('—')
                                ->columnSpan(2),
                        ]),

                        Grid::make(3)->schema([
                            TextEntry::make('emitted_at')
                                ->label('Emitida')
                                ->dateTime('d/m/Y H:i:s')
                                ->placeholder('—'),

                            TextEntry::make('creator.name')
                                ->label('Registrada por')
                                ->placeholder('Sistema'),

                            TextEntry::make('created_at')
                                ->label('Creada')
                                ->dateTime('d/m/Y H:i:s'),
                        ]),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}

<?php

namespace App\Filament\Resources\CreditNotes;

use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\CreditNotes\Pages\PrintCreditNote;
use App\Filament\Resources\CreditNotes\Pages\ViewCreditNote;
use App\Filament\Resources\CreditNotes\Schemas\CreditNoteInfolist;
use App\Filament\Resources\CreditNotes\Tables\CreditNotesTable;
use App\Models\CreditNote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recurso de panel para Notas de Crédito (SAR tipo '03').
 *
 * Read-only en creación: las NC se emiten desde ViewInvoice — no desde este
 * listado (mismo patrón que Invoice no se crea desde InvoiceResource sino
 * desde el POS). Read-write solo para anular, vía CreditNoteService para
 * garantizar la reversión transaccional del kardex.
 *
 * Simétrico a InvoiceResource en:
 *   - navigationGroup "Finanzas"
 *   - canCreate() = false
 *   - getNavigationBadge() — NCs emitidas hoy
 *   - getGloballySearchableAttributes — campos fiscales buscables
 *   - separación en Pages/ y Tables/
 */
class CreditNoteResource extends Resource
{
    protected static ?string $model = CreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?string $recordTitleAttribute = 'credit_note_number';

    protected static ?string $modelLabel = 'Nota de Crédito';

    protected static ?string $pluralModelLabel = 'Notas de Crédito';

    /**
     * Después de Invoice (navigationSort=2) — el flujo mental del operador
     * es Ventas → Facturas → Notas de Crédito.
     */
    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Finanzas';
    }

    public static function getNavigationBadge(): ?string
    {
        // NCs válidas emitidas hoy — simétrico al badge de Invoice.
        $today = static::getModel()::valid()
            ->whereDate('credit_note_date', today())
            ->count();

        return $today > 0 ? (string) $today : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return CreditNotesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CreditNoteInfolist::configure($schema);
    }

    /**
     * Eager load de relaciones usadas en columnas y row actions de la tabla.
     * Evita N+1 al renderizar creator.name, invoice.* (para links), etc.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['creator:id,name', 'invoice:id,invoice_number']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCreditNotes::route('/'),
            'view'  => ViewCreditNote::route('/{record}'),
            'print' => PrintCreditNote::route('/{record}/print'),
        ];
    }

    /**
     * Las NC se emiten exclusivamente desde la acción "Emitir NC" dentro de
     * ViewInvoice (F5b). Nunca desde el listado — necesitan una factura
     * origen, líneas seleccionadas y una razón, lo cual no encaja en un
     * "crear" genérico de Filament.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'credit_note_number',
            'original_invoice_number',
            'customer_name',
            'customer_rtn',
            'cai',
        ];
    }
}

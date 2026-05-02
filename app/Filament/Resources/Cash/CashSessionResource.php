<?php

namespace App\Filament\Resources\Cash;

use App\Filament\Resources\Cash\Pages\ListCashSessions;
use App\Filament\Resources\Cash\Pages\PrintCashSession;
use App\Filament\Resources\Cash\Pages\ViewCashSession;
use App\Filament\Resources\Cash\RelationManagers\CashMovementsRelationManager;
use App\Filament\Resources\Cash\Schemas\CashSessionInfolist;
use App\Filament\Resources\Cash\Tables\CashSessionsTable;
use App\Models\CashSession;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resource de sesiones de caja.
 *
 * No expone página Create/Edit:
 *   - Abrir se dispara vía header action "Abrir caja" (C3.2).
 *   - Cerrar se dispara vía record action "Cerrar mi caja" (C3.3).
 *   - Gastos se registran vía action "Registrar gasto" (C3.4).
 *
 * Las sesiones son documentos operativos inmutables una vez cerradas.
 */
class CashSessionResource extends Resource
{
    protected static ?string $model = CashSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $modelLabel = 'Sesión de Caja';

    protected static ?string $pluralModelLabel = 'Sesiones de Caja';

    /**
     * Sort 1 dentro de "Finanzas" — operacionalmente el cajero abre caja
     * antes de facturar, así que aparece primero en el menú del grupo.
     */
    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Operación';
    }

    /**
     * Badge con el número de sesiones abiertas — 0 significa "todos cerraron".
     * Un 1 persistente a las 8pm es señal de que alguien olvidó cerrar.
     */
    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::query()->whereNull('closed_at')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return CashSessionsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CashSessionInfolist::configure($schema);
    }

    /**
     * Eager load las relaciones que el listado y el infolist consumen.
     * Sin esto: N+1 garantizado al mostrar "abierta por: {nombre}" y "sucursal".
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['establishment', 'openedBy', 'closedBy', 'authorizedBy']);
    }

    /**
     * RelationManager read-only de movimientos. Se monta en el ViewCashSession.
     * Sin Update/Delete: el kardex es historia operativa inmutable.
     */
    public static function getRelations(): array
    {
        return [
            CashMovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashSessions::route('/'),
            'view'  => ViewCashSession::route('/{record}'),
            // Hoja de cierre / corte parcial embebida — el iframe interno carga
            // la ruta web `cash-sessions.print` que es la misma vista PDF que
            // se imprime. Mantener el sidebar/navbar visibles fue requisito UX.
            'print' => PrintCashSession::route('/{record}/print'),
        ];
    }

    /**
     * La creación ocurre vía action "Abrir caja" en la list page, no vía
     * formulario estándar — hay lógica transaccional (lockForUpdate,
     * validación de sesión abierta existente) que solo vive en el Service.
     */
    public static function canCreate(): bool
    {
        return false;
    }
}

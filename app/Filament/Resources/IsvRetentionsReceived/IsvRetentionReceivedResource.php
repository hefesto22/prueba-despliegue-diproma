<?php

namespace App\Filament\Resources\IsvRetentionsReceived;

use App\Filament\Resources\IsvRetentionsReceived\Pages\CreateIsvRetentionReceived;
use App\Filament\Resources\IsvRetentionsReceived\Pages\EditIsvRetentionReceived;
use App\Filament\Resources\IsvRetentionsReceived\Pages\ListIsvRetentionsReceived;
use App\Filament\Resources\IsvRetentionsReceived\Pages\ViewIsvRetentionReceived;
use App\Filament\Resources\IsvRetentionsReceived\Schemas\IsvRetentionReceivedForm;
use App\Filament\Resources\IsvRetentionsReceived\Schemas\IsvRetentionReceivedInfolist;
use App\Filament\Resources\IsvRetentionsReceived\Tables\IsvRetentionsReceivedTable;
use App\Models\IsvRetentionReceived;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Retenciones ISV recibidas — insumo para la sección C del Formulario 201
 * (ISV mensual SIISAR).
 *
 * Los registros alimentan `IsvMonthlyDeclarationService` (ISV.4) para calcular
 * el total de créditos del período. Por eso la edición no se restringe por
 * estado propio — se restringe por si la declaración del período ya fue
 * marcada como presentada (ese gate vive en la Policy + Observer de
 * IsvMonthlyDeclaration, ISV.3).
 */
class IsvRetentionReceivedResource extends Resource
{
    protected static ?string $model = IsvRetentionReceived::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $recordTitleAttribute = 'agent_name';

    protected static ?string $modelLabel = 'Retención ISV Recibida';

    protected static ?string $pluralModelLabel = 'Retenciones ISV Recibidas';

    /**
     * Sort 3 dentro de "Fiscal": después de Períodos Fiscales (1) y
     * Libros Fiscales (2). Las retenciones son el insumo operativo
     * del mes — el contador las carga a medida que llegan las constancias.
     */
    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Fiscal';
    }

    public static function form(Schema $schema): Schema
    {
        return IsvRetentionReceivedForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return IsvRetentionReceivedInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IsvRetentionsReceivedTable::configure($table);
    }

    /**
     * Eager-load para evitar N+1 en listado e infolist:
     *   - establishment: columna "Sucursal"
     *   - createdBy: columna "Creado por" (toggleable)
     *
     * withoutGlobalScopes(SoftDeletingScope): el TrashedFilter del listado
     * controla la visibilidad de registros eliminados.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['establishment', 'createdBy'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['agent_rtn', 'agent_name', 'document_number'];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListIsvRetentionsReceived::route('/'),
            'create' => CreateIsvRetentionReceived::route('/create'),
            'view'   => ViewIsvRetentionReceived::route('/{record}'),
            'edit'   => EditIsvRetentionReceived::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Repairs;

use App\Filament\Resources\Repairs\Pages\CreateRepair;
use App\Filament\Resources\Repairs\Pages\EditRepair;
use App\Filament\Resources\Repairs\Pages\ListRepairs;
use App\Filament\Resources\Repairs\Pages\ViewRepair;
use App\Filament\Resources\Repairs\RelationManagers\RepairItemsRelationManager;
use App\Filament\Resources\Repairs\RelationManagers\RepairPhotosRelationManager;
use App\Filament\Resources\Repairs\Schemas\RepairForm;
use App\Filament\Resources\Repairs\Schemas\RepairInfolist;
use App\Filament\Resources\Repairs\Tables\RepairsTable;
use App\Models\Repair;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RepairResource extends Resource
{
    protected static ?string $model = Repair::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $recordTitleAttribute = 'repair_number';

    protected static ?string $modelLabel = 'Reparación';

    protected static ?string $pluralModelLabel = 'Reparaciones';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'Operación';
    }

    public static function form(Schema $schema): Schema
    {
        return RepairForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RepairInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RepairsTable::configure($table);
    }

    /**
     * Eager-load relaciones que las columnas y la nav badge usan.
     * Sin esto el listado de 50 reparaciones dispara N+1 al pintar
     * "cliente", "técnico" y "categoría de equipo" por cada fila.
     *
     * `withoutGlobalScopes(SoftDeletingScope::class)` para que el filtro
     * "Eliminados" pueda mostrar las soft-deleted (consistente con CustomerResource).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'customer:id,name,phone,rtn',
                'deviceCategory:id,name,icon',
                'technician:id,name',
            ])
            ->withCount('items');
    }

    public static function getRelations(): array
    {
        return [
            RepairItemsRelationManager::class,
            RepairPhotosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepairs::route('/'),
            'create' => CreateRepair::route('/create'),
            'view' => ViewRepair::route('/{record}'),
            'edit' => EditRepair::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'repair_number',
            'qr_token',
            'customer_name',
            'customer_phone',
            'customer_rtn',
            'device_brand',
            'device_model',
            'device_serial',
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        // Cuenta reparaciones activas (no terminales) — útil para que el
        // técnico/admin vea de un vistazo cuántas hay en el flujo.
        // Cache 60s para evitar query en cada render del nav.
        return cache()->remember(
            'repairs:nav:active_count',
            60,
            fn () => (string) Repair::active()->count(),
        );
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}

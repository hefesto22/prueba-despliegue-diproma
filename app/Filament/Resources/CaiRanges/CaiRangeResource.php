<?php

namespace App\Filament\Resources\CaiRanges;

use App\Filament\Resources\CaiRanges\Pages;
use App\Filament\Resources\CaiRanges\Schemas\CaiRangeForm;
use App\Filament\Resources\CaiRanges\Tables\CaiRangesTable;
use App\Models\CaiRange;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CaiRangeResource extends Resource
{
    protected static ?string $model = CaiRange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'CAI / Rangos';

    protected static ?string $modelLabel = 'Rango CAI';

    protected static ?string $pluralModelLabel = 'Rangos CAI';

    protected static ?int $navigationSort = 98;

    public static function getNavigationGroup(): ?string
    {
        return 'Administración';
    }

    public static function getNavigationBadge(): ?string
    {
        $active = CaiRange::active()->forInvoices()->notExpired()->first();

        if (! $active) {
            return 'Sin CAI';
        }

        if ($active->isNearExhaustion()) {
            return $active->remaining . ' restantes';
        }

        return null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $active = CaiRange::active()->forInvoices()->notExpired()->first();

        if (! $active) {
            return 'danger';
        }

        if ($active->isNearExhaustion()) {
            return 'warning';
        }

        return null;
    }

    public static function form(Schema $schema): Schema
    {
        return CaiRangeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CaiRangesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCaiRanges::route('/'),
            'create' => Pages\CreateCaiRange::route('/create'),
            'edit' => Pages\EditCaiRange::route('/{record}/edit'),
        ];
    }
}

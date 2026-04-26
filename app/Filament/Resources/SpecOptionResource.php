<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SpecOptionResource\Pages;
use App\Models\SpecOption;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SpecOptionResource extends Resource
{
    protected static ?string $model = SpecOption::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $modelLabel = 'Opción de Spec';

    protected static ?string $pluralModelLabel = 'Opciones de Especificaciones';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Catálogo';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('field_key')
                ->label('Campo')
                ->options(self::fieldKeyLabels())
                ->required()
                ->searchable(),
            TextInput::make('value')
                ->label('Valor')
                ->required()
                ->maxLength(255)
                ->dehydrateStateUsing(fn ($state) => mb_strtoupper(trim($state))),
            TextInput::make('sort_order')
                ->label('Orden')
                ->numeric()
                ->default(0)
                ->minValue(0),
            Toggle::make('is_active')
                ->label('Activo')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('field_key')
                    ->label('Campo')
                    ->formatStateUsing(fn (string $state) => self::fieldKeyLabels()[$state] ?? $state)
                    ->sortable()
                    ->searchable(),
                TextColumn::make('value')
                    ->label('Valor')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('sort_order')
                    ->label('Orden')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->defaultSort('field_key')
            ->filters([
                SelectFilter::make('field_key')
                    ->label('Campo')
                    ->options(self::fieldKeyLabels())
                    ->multiple(),
                TernaryFilter::make('is_active')
                    ->label('Activo'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSpecOptions::route('/'),
            'create' => Pages\CreateSpecOption::route('/create'),
            'edit' => Pages\EditSpecOption::route('/{record}/edit'),
        ];
    }

    /**
     * Etiquetas legibles para los field_keys.
     */
    private static function fieldKeyLabels(): array
    {
        return [
            'processor' => 'Procesador',
            'ram' => 'RAM',
            'storage' => 'Almacenamiento',
            'screen' => 'Pantalla',
            'os' => 'Sistema Operativo',
            'gpu' => 'Gráficos',
            'case_type' => 'Gabinete',
            'connectivity' => 'Conectividad',
            'edition' => 'Edición (Consola)',
            'resolution' => 'Resolución',
            'panel' => 'Panel',
            'refresh' => 'Frecuencia',
            'printer_type' => 'Tipo Impresora',
            'printer_functions' => 'Funciones Impresora',
            'component_type' => 'Tipo Componente',
            'comp_interface' => 'Interfaz',
            'accessory_type' => 'Tipo Accesorio',
        ];
    }
}

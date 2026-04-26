<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Categoría')
                    ->aside()
                    ->description('Nombre y organización de la categoría.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Ej: Laptops'),
                        Grid::make(2)->schema([
                            Select::make('parent_id')
                                ->label('Categoría padre')
                                ->relationship(
                                    name: 'parent',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: fn ($query, $record) => $query
                                        ->active()
                                        ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                                )
                                ->searchable()
                                ->preload()
                                ->placeholder('Ninguna (raíz)'),
                            TextInput::make('sort_order')
                                ->label('Orden')
                                ->numeric()
                                ->default(0)
                                ->minValue(0),
                        ]),
                        Textarea::make('description')
                            ->label('Descripción')
                            ->rows(2)
                            ->maxLength(1000)
                            ->placeholder('Descripción opcional'),
                        Grid::make(2)->schema([
                            TextInput::make('slug')
                                ->label('Slug')
                                ->maxLength(255)
                                ->unique(ignoreRecord: true)
                                ->placeholder('Se genera automáticamente'),
                            Toggle::make('is_active')
                                ->label('Activa')
                                ->default(true)
                                ->onColor('success')
                                ->offColor('danger'),
                        ]),
                    ]),
            ]);
    }
}

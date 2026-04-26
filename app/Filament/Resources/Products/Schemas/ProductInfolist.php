<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\TaxType;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Producto')
                    ->schema([
                        TextEntry::make('name')
                            ->label('')
                            ->weight('bold')
                            ->size('lg'),
                        Grid::make(4)->schema([
                            TextEntry::make('sku')
                                ->label('SKU')
                                ->badge()
                                ->color('gray'),
                            TextEntry::make('product_type')
                                ->label('Tipo')
                                ->badge()
                                ->color('info'),
                            TextEntry::make('brand')
                                ->label('Marca')
                                ->placeholder('—'),
                            TextEntry::make('condition')
                                ->label('Condición')
                                ->badge()
                                ->color(fn ($state) => $state->value === 'new' ? 'success' : 'warning'),
                        ]),
                    ]),

                Section::make('Especificaciones')
                    ->schema([
                        TextEntry::make('specs')
                            ->label('')
                            ->formatStateUsing(function ($state, $record) {
                                if (!is_array($state) || empty($state)) {
                                    return 'Sin especificaciones';
                                }
                                $labels = [];
                                if ($record->product_type) {
                                    foreach ($record->product_type->specFields() as $field) {
                                        $labels[$field['key']] = $field['label'];
                                    }
                                }
                                return collect($state)
                                    ->map(fn ($value, $key) => '**' . ($labels[$key] ?? ucfirst($key)) . ':** ' . $value)
                                    ->implode("\n\n");
                            })
                            ->markdown(),
                    ])
                    ->visible(fn ($record) => !empty($record->specs)),

                Section::make('Precio')
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('sale_price_with_isv')
                                ->label('Precio venta')
                                ->money('HNL')
                                ->weight('bold'),
                            TextEntry::make('cost_price_with_isv')
                                ->label('Costo')
                                ->money('HNL'),
                            TextEntry::make('tax_type')
                                ->label('Fiscal')
                                ->badge()
                                ->color(fn ($state) => $state->value === 'gravado_15' ? 'info' : 'gray'),
                            TextEntry::make('profit_margin')
                                ->label('Margen')
                                ->suffix('%')
                                ->color(fn ($state) => $state >= 20 ? 'success' : ($state >= 10 ? 'warning' : 'danger')),
                        ]),
                    ]),

                Section::make('Inventario')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('stock')
                                ->label('Stock')
                                ->badge()
                                ->color(fn ($record) => $record->isOutOfStock() ? 'danger' : ($record->isLowStock() ? 'warning' : 'success')),
                            TextEntry::make('min_stock')
                                ->label('Stock mínimo'),
                            TextEntry::make('is_active')
                                ->label('Estado')
                                ->badge()
                                ->formatStateUsing(fn (bool $state) => $state ? 'Activo' : 'Inactivo')
                                ->color(fn (bool $state) => $state ? 'success' : 'danger'),
                        ]),
                    ]),

                Section::make('Seriales')
                    ->visible(fn ($record) => !empty($record->serial_numbers))
                    ->schema([
                        TextEntry::make('serial_numbers')
                            ->label('')
                            ->badge()
                            ->separator(','),
                    ]),

                Section::make('Imagen')
                    ->visible(fn ($record) => filled($record->image_path))
                    ->collapsible()
                    ->schema([
                        ImageEntry::make('image_path')
                            ->label('')
                            ->height(200),
                    ]),
            ]);
    }
}

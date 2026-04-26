<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductCondition;
use App\Enums\ProductType;
use App\Enums\TaxType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?name=P&color=FFFFFF&background=6366F1'),
                TextColumn::make('name')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->copyMessage('SKU copiado'),
                TextColumn::make('product_type')
                    ->label('Tipo')
                    ->badge()
                    ->color('info'),
                TextColumn::make('condition')
                    ->label('Condición')
                    ->badge()
                    ->color(fn (ProductCondition $state) => $state === ProductCondition::New ? 'success' : 'warning'),
                TextColumn::make('sale_price_with_isv')
                    ->label('Precio')
                    ->money('HNL')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('sale_price', $direction))
                    ->description(fn ($record) => $record->tax_type === TaxType::Gravado15 ? 'ISV incluido' : 'Exento'),
                TextColumn::make('stock')
                    ->label('Stock')
                    ->sortable()
                    ->badge()
                    ->color(fn ($record) => $record->isOutOfStock() ? 'danger' : ($record->isLowStock() ? 'warning' : 'success')),
                ToggleColumn::make('is_active')
                    ->label('Activo')
                    ->onColor('success')
                    ->offColor('danger'),
                TextColumn::make('brand')
                    ->label('Marca')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cost_price_with_isv')
                    ->label('Costo')
                    ->money('HNL')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('cost_price', $direction))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('product_type')
                    ->label('Tipo')
                    ->options(ProductType::class)
                    ->multiple(),
                SelectFilter::make('brand')
                    ->label('Marca')
                    ->options(fn () => \App\Models\Product::query()
                        ->whereNotNull('brand')
                        ->distinct()
                        ->pluck('brand', 'brand')
                        ->toArray()
                    )
                    ->multiple(),
                SelectFilter::make('condition')
                    ->label('Condición')
                    ->options(ProductCondition::class),
                TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),
                Filter::make('low_stock')
                    ->label('Stock bajo')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->lowStock()),
                Filter::make('out_of_stock')
                    ->label('Sin stock')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->outOfStock()),
                TrashedFilter::make()
                    ->label('Eliminados'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Sales\Tables;

use App\Enums\SaleStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class SalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sale_number')
                    ->label('# Venta')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon('heroicon-o-shopping-bag'),
                TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('establishment.name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('customer_rtn')
                    ->label('RTN')
                    ->placeholder('Consumidor Final')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('discount_amount')
                    ->label('Desc.')
                    ->money('HNL')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->sortable(),
                TextColumn::make('createdBy.name')
                    ->label('Vendedor')
                    ->placeholder('Sistema')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Hora')
                    ->dateTime('H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(SaleStatus::class)
                    ->placeholder('Todos'),
                SelectFilter::make('establishment_id')
                    ->label('Sucursal')
                    ->relationship('establishment', 'name', fn ($query) => $query->where('is_active', true))
                    ->searchable()
                    ->preload()
                    ->placeholder('Todas'),
                TrashedFilter::make()
                    ->label('Eliminadas'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}

<?php

namespace App\Filament\Resources\InventoryMovements\Tables;

use App\Enums\MovementType;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('product.sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('product.name')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->numeric()
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('unit_cost')
                    ->label('Costo Unit.')
                    ->money('HNL')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('total_value')
                    ->label('Valor Mov.')
                    ->money('HNL')
                    ->alignEnd()
                    ->placeholder('—')
                    ->state(fn ($record): ?float => $record->total_value)
                    ->toggleable(),
                TextColumn::make('stock_before')
                    ->label('Stock Antes')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->color('gray'),
                TextColumn::make('stock_after')
                    ->label('Stock Después')
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->weight('bold'),
                TextColumn::make('notes')
                    ->label('Notas')
                    ->limit(50)
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('createdBy.name')
                    ->label('Responsable')
                    ->placeholder('Sistema')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(MovementType::class)
                    ->placeholder('Todos'),
                SelectFilter::make('product_id')
                    ->label('Producto')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Todos'),
                Filter::make('date_range')
                    ->indicateUsing(function (array $data): ?string {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;
                        if (! $from && ! $until) {
                            return null;
                        }
                        return 'Rango: ' . ($from ?? '…') . ' → ' . ($until ?? '…');
                    })
                    ->schema([
                        DatePicker::make('from')->label('Desde'),
                        DatePicker::make('until')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date)
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)
                            );
                    }),
            ])
            ->recordActions([
                // Movimientos son inmutables — solo lectura
            ])
            ->toolbarActions([
                // Sin bulk actions — no se eliminan movimientos
            ]);
    }
}

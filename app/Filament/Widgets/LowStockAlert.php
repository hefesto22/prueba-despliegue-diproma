<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LowStockAlert extends TableWidget
{
    protected static ?int $sort = 7;

    protected int | string | array $columnSpan = 'full';

    protected ?string $pollingInterval = '300s';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Alerta — Productos con Stock Bajo')
            ->description('Productos activos por debajo del stock mínimo configurado')
            ->query(
                Product::query()
                    ->select(['id', 'sku', 'name', 'brand', 'stock', 'min_stock', 'is_active', 'sale_price'])
                    ->active()
                    ->where(function ($query) {
                        $query->whereColumn('stock', '<=', 'min_stock')
                            ->orWhere('stock', '<=', 0);
                    })
                    ->orderBy('stock')
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->copyable()
                    ->weight('bold')
                    ->color('primary'),

                TextColumn::make('name')
                    ->label('Producto')
                    ->searchable()
                    ->limit(45)
                    ->tooltip(fn (Product $record): string => $record->name),

                TextColumn::make('brand')
                    ->label('Marca')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('stock')
                    ->label('Stock')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (Product $record): string => match (true) {
                        $record->stock <= 0 => 'danger',
                        $record->stock <= $record->min_stock => 'warning',
                        default => 'success',
                    })
                    ->formatStateUsing(fn (int $state, Product $record): string => match (true) {
                        $state <= 0 => 'AGOTADO',
                        default => (string) $state,
                    }),

                TextColumn::make('min_stock')
                    ->label('Mínimo')
                    ->alignCenter(),

                TextColumn::make('sale_price')
                    ->label('Precio Venta')
                    ->money('HNL')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('view_product')
                        ->label('Ver producto')
                        ->icon('heroicon-m-cube')
                        ->url(fn (Product $record): string => ProductResource::getUrl('view', ['record' => $record])),

                    Action::make('create_purchase')
                        ->label('Crear orden de compra')
                        ->icon('heroicon-m-shopping-bag')
                        ->color('success')
                        ->url(fn (): string => PurchaseResource::getUrl('create')),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->size('sm'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Sin alertas de stock')
            ->emptyStateDescription('Todos los productos activos están por encima de su stock mínimo.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}

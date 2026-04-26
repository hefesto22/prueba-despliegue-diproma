<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Sales\SaleResource;
use App\Models\Sale;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestSales extends TableWidget
{
    protected static ?int $sort = 6;

    protected int | string | array $columnSpan = 'full';

    protected ?string $pollingInterval = '120s';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Últimas Ventas')
            ->description('Últimas 10 transacciones registradas')
            ->query(
                Sale::query()
                    ->select(['id', 'sale_number', 'customer_name', 'date', 'status', 'total', 'discount_amount'])
                    ->latest('date')
                    ->latest('id')
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('sale_number')
                    ->label('N.º Venta')
                    ->weight('bold')
                    ->color('primary')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->placeholder('Consumidor Final')
                    ->searchable()
                    ->limit(30),

                TextColumn::make('date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (Sale $record): string => $record->date?->diffForHumans() ?? ''),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),

                TextColumn::make('discount_amount')
                    ->label('Desc.')
                    ->money('HNL')
                    ->alignEnd()
                    ->color('warning')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Ver')
                    ->icon('heroicon-m-eye')
                    ->iconButton()
                    ->url(fn (Sale $record): string => SaleResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(false),
            ])
            ->paginated(false)
            ->emptyStateHeading('Aún no hay ventas')
            ->emptyStateDescription('Las ventas aparecerán aquí cuando se procesen desde el POS.')
            ->emptyStateIcon('heroicon-o-shopping-cart');
    }
}

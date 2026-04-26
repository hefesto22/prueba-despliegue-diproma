<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Enums\MovementType;
use App\Exports\KardexExport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Kardex por producto: aparece como pestaña al ver/editar un producto.
 *
 * Read-only por diseño — los movimientos se crean únicamente vía:
 *   - PurchaseService (confirm/cancel)
 *   - SaleService (processSale/cancel)
 *   - CreateInventoryMovement page (ajustes manuales)
 *
 * Esto garantiza la inmutabilidad y auditoría del kardex.
 */
class KardexRelationManager extends RelationManager
{
    protected static string $relationship = 'inventoryMovements';

    protected static ?string $title = 'Kardex';

    protected static string|BackedEnum|null $icon = 'heroicon-o-clipboard-document-list';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Movimientos de Kardex')
            ->description('Historial completo con saldo corriente y costo al momento del movimiento')
            // Eager load de `createdBy` — la columna "Responsable" es toggleable
            // (oculta por default), pero cuando el usuario la activa ocurría N+1:
            // 1 query por movimiento para resolver el nombre del usuario.
            // Solo selecciono id+name porque la UI no usa más campos del user.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('createdBy:id,name'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),

                TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->numeric()
                    ->alignCenter()
                    ->color(fn ($record): string => $record->type->isEntry() ? 'success' : 'danger')
                    ->formatStateUsing(
                        fn ($state, $record): string =>
                        ($record->type->isEntry() ? '+' : '−') . number_format((int) $state)
                    ),

                TextColumn::make('unit_cost')
                    ->label('Costo Unit.')
                    ->money('HNL')
                    ->placeholder('—')
                    ->alignEnd()
                    ->tooltip('Costo al momento exacto del movimiento'),

                TextColumn::make('total_value')
                    ->label('Valor Mov.')
                    ->money('HNL')
                    ->placeholder('—')
                    ->alignEnd()
                    ->state(fn ($record): ?float => $record->total_value),

                TextColumn::make('stock_before')
                    ->label('Antes')
                    ->numeric()
                    ->alignCenter()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('stock_after')
                    ->label('Saldo')
                    ->numeric()
                    ->alignCenter()
                    ->weight('bold'),

                TextColumn::make('notes')
                    ->label('Notas')
                    ->limit(40)
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
            ->headerActions([
                Action::make('export')
                    ->label('Exportar Excel')
                    ->icon('heroicon-m-document-arrow-down')
                    ->color('success')
                    ->action(function ($livewire) {
                        $product = $livewire->getOwnerRecord();
                        $safeSku = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $product->sku);
                        $filename = "kardex-{$safeSku}-" . now()->format('Ymd-His') . '.xlsx';

                        return Excel::download(
                            new KardexExport(
                                baseQuery: $livewire->getFilteredTableQuery(),
                                titleSuffix: $product->sku,
                            ),
                            $filename,
                        );
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->paginated([25, 50, 100]);
    }

    public function canCreate(): bool
    {
        return false;
    }
}

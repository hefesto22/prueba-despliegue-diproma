<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Tables;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Tabla de Gastos — vista admin/contador.
 *
 * Diseño de filtros pensado para el flow real del contador en el cierre
 * mensual:
 *
 *   1. Filtro combinado de período (año+mes) — equivalente al usado en
 *      IsvRetentionsReceivedTable. Una sola fila en UI, dos columnas
 *      aplicadas al query. Los índices `expenses_estab_date_idx` y
 *      `expenses_*_date_idx` cubren estas queries.
 *
 *   2. Filtros independientes por categoría, método de pago, sucursal,
 *      deducibilidad — cada uno con su índice correspondiente. El
 *      contador combina libremente.
 *
 *   3. Sin TrashedFilter — el modelo Expense NO usa SoftDeletes (decisión
 *      consciente de la migración: gastos son registros fiscales).
 *
 * Sin Delete actions — la Policy también lo bloquea, pero acá lo omitimos
 * directamente para no mostrar un botón que va a fallar.
 *
 * Default sort: expense_date DESC + id DESC. Lo más reciente arriba para
 * el flow típico de revisión "qué se gastó esta semana".
 */
class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('expense_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable()
                    ->icon('heroicon-o-calendar')
                    ->weight('medium'),

                TextColumn::make('category')
                    ->label('Categoría')
                    ->badge()
                    ->formatStateUsing(fn (ExpenseCategory $state) => $state->getLabel())
                    ->color(fn (ExpenseCategory $state) => $state->getColor())
                    ->icon(fn (ExpenseCategory $state) => $state->getIcon())
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Descripción')
                    ->searchable()
                    ->wrap()
                    ->limit(60)
                    ->tooltip(fn (Expense $record): string => $record->description),

                TextColumn::make('amount_total')
                    ->label('Monto')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->alignEnd(),

                TextColumn::make('payment_method')
                    ->label('Método')
                    ->badge()
                    ->formatStateUsing(fn (PaymentMethod $state) => $state->getLabel())
                    ->color(fn (PaymentMethod $state) => $state->getColor())
                    ->icon(fn (PaymentMethod $state) => $state->getIcon())
                    ->sortable(),

                TextColumn::make('provider_name')
                    ->label('Proveedor')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('provider_rtn')
                    ->label('RTN proveedor')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('provider_invoice_number')
                    ->label('# Factura')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_isv_deductible')
                    ->label('Deducible')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('isv_amount')
                    ->label('ISV')
                    ->money('HNL')
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('establishment.name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('user.name')
                    ->label('Registrado por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // expense_date desc + id desc → "lo último de hoy arriba".
            // El id desc desempata gastos del mismo día por orden de captura.
            ->defaultSort('expense_date', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->orderByDesc('id'))
            ->filters([
                // Filtro combinado año+mes — UX consistente con IsvRetention.
                Filter::make('period')
                    ->label('Período')
                    ->schema([
                        Select::make('period_year')
                            ->label('Año')
                            ->options(self::yearOptions())
                            ->placeholder('Cualquiera'),
                        Select::make('period_month')
                            ->label('Mes')
                            ->options(self::monthOptions())
                            ->placeholder('Cualquiera'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['period_year'] ?? null, fn ($q, $y) => $q->whereYear('expense_date', $y))
                            ->when($data['period_month'] ?? null, fn ($q, $m) => $q->whereMonth('expense_date', $m));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($y = $data['period_year'] ?? null) {
                            $indicators[] = "Año: {$y}";
                        }
                        if ($m = $data['period_month'] ?? null) {
                            $indicators[] = 'Mes: ' . self::monthOptions()[$m];
                        }

                        return $indicators;
                    }),

                SelectFilter::make('category')
                    ->label('Categoría')
                    ->options(ExpenseCategory::class)
                    ->placeholder('Todas'),

                SelectFilter::make('payment_method')
                    ->label('Método de pago')
                    ->options(PaymentMethod::class)
                    ->placeholder('Todos'),

                SelectFilter::make('establishment_id')
                    ->label('Sucursal')
                    ->relationship('establishment', 'name', fn ($query) => $query->where('is_active', true))
                    ->searchable()
                    ->preload()
                    ->placeholder('Todas'),

                TernaryFilter::make('is_isv_deductible')
                    ->label('Deducible de ISV')
                    ->placeholder('Todos')
                    ->trueLabel('Solo deducibles')
                    ->falseLabel('Solo no deducibles'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            // Sin BulkActions de delete — los gastos no se eliminan.
            // Si en el futuro se necesita "anular en lote", se agrega
            // una BulkAction custom que setee voided_at.
            ->toolbarActions([
                BulkActionGroup::make([
                    //
                ]),
            ]);
    }

    /**
     * Años disponibles en el filtro: año actual + 2 previos + 1 futuro.
     * Cubre el 99% de gastos sin saturar el dropdown con años irrelevantes.
     */
    private static function yearOptions(): array
    {
        $current = now()->year;
        $years = range($current - 2, $current + 1);

        return array_combine($years, array_map('strval', $years));
    }

    private static function monthOptions(): array
    {
        return [
            1  => 'Enero',
            2  => 'Febrero',
            3  => 'Marzo',
            4  => 'Abril',
            5  => 'Mayo',
            6  => 'Junio',
            7  => 'Julio',
            8  => 'Agosto',
            9  => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];
    }
}

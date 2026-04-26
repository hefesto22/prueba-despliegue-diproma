<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cash\RelationManagers;

use App\Enums\CashMovementType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\CashMovement;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * RelationManager de movimientos de una sesión de caja.
 *
 * READ-ONLY POR DISEÑO.
 *
 * Los movimientos se crean exclusivamente vía:
 *   - CashSessionService::open()      → OpeningBalance
 *   - CashSessionService::close()     → ClosingBalance
 *   - SaleService::process()          → SaleIncome
 *   - SaleService::cancel()           → SaleCancellation
 *   - RecordExpenseAction             → Expense
 *
 * NO se exponen create/edit/delete actions ni en el header ni por row. Esto
 * es consistente con CashMovementPolicy que documenta que los permisos
 * Update/Delete NO deben concederse a ningún rol en producción. El kardex
 * de caja es historia operativa inmutable para auditoría interna.
 *
 * Performance:
 *   - Eager load de `user` en la query (se muestra en cada row). Sin esto: N+1.
 *   - Default sort por `occurred_at desc` → índice `cash_mov_session_occurred_idx`
 *     cubre el orden.
 *   - Sin paginación agresiva: una sesión típica tiene 50-200 movimientos.
 *     Si una sucursal supera 1000 movimientos/día se paginará a 50.
 */
class CashMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Movimientos de la sesión';

    // Tipo del parent en Filament v4 es BackedEnum|string|null — no ?string.
    // Usar el enum Heroicon respeta el contrato y es consistente con el Resource.
    protected static string|BackedEnum|null $icon = Heroicon::OutlinedListBullet;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user:id,name'))
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Fecha / hora')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),

                TextColumn::make('payment_method')
                    ->label('Método')
                    ->badge()
                    ->color(fn (?PaymentMethod $state): string => $state?->getColor() ?? 'gray')
                    ->icon(fn (?PaymentMethod $state): ?string => $state?->getIcon())
                    ->formatStateUsing(fn (?PaymentMethod $state): string => $state?->getLabel() ?? '—'),

                TextColumn::make('category')
                    ->label('Categoría')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?ExpenseCategory $state): string => $state?->getColor() ?? 'gray')
                    ->icon(fn (?ExpenseCategory $state): ?string => $state?->getIcon())
                    ->formatStateUsing(fn (?ExpenseCategory $state): string => $state?->getLabel() ?? '—')
                    ->toggleable(),

                // Monto con signo contable: ingresos verde, egresos rojo.
                // El valor en DB siempre es positivo; el color lo determina el type.
                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('HNL')
                    ->weight('bold')
                    ->alignEnd()
                    ->color(function (CashMovement $record): string {
                        if ($record->type === CashMovementType::OpeningBalance
                            || $record->type === CashMovementType::ClosingBalance
                        ) {
                            return 'gray';
                        }

                        if ($record->type->isInflow()) {
                            return 'success';
                        }

                        if ($record->type->isOutflow()) {
                            return 'danger';
                        }

                        return 'gray';
                    })
                    ->formatStateUsing(function (CashMovement $record, $state): string {
                        $prefix = match (true) {
                            $record->type->isOutflow()                     => '− ',
                            $record->type->isInflow()
                                && $record->type !== CashMovementType::OpeningBalance
                                && $record->type !== CashMovementType::ClosingBalance => '+ ',
                            default                                        => '',
                        };

                        return $prefix . 'L. ' . number_format((float) $state, 2);
                    }),

                TextColumn::make('user.name')
                    ->label('Usuario')
                    ->icon('heroicon-o-user')
                    ->toggleable(),

                TextColumn::make('description')
                    ->label('Descripción')
                    ->wrap()
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—'),

                // Referencia a Sale u otro modelo, visible para rastreo cruzado.
                // Oculto por default — solo los auditores lo miran habitualmente.
                TextColumn::make('reference')
                    ->label('Referencia')
                    ->getStateUsing(function (CashMovement $record): ?string {
                        if ($record->reference_type === null || $record->reference_id === null) {
                            return null;
                        }

                        $type = class_basename($record->reference_type);

                        return sprintf('%s #%d', $type, $record->reference_id);
                    })
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo de movimiento')
                    ->options(CashMovementType::class)
                    ->multiple(),

                SelectFilter::make('payment_method')
                    ->label('Método de pago')
                    ->options(PaymentMethod::class)
                    ->multiple(),

                SelectFilter::make('user_id')
                    ->label('Registrado por')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
            ])
            // Read-only: sin header actions, sin row actions, sin bulk actions.
            // Los movimientos son historia operativa inmutable.
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * Hard block del create desde el RelationManager — los movimientos solo
     * se crean vía Service / Action / POS. Filament v4 define `canCreate`
     * como método de instancia (no static), así que respetamos esa firma.
     *
     * Adicionalmente: la Policy `CashMovementPolicy` no debería conceder
     * `Create:CashMovement` a roles de usuario final. Este `return false`
     * es defense-in-depth: incluso si alguien marca el permiso por error,
     * la UI no expone la creación.
     */
    public function canCreate(): bool
    {
        return false;
    }
}

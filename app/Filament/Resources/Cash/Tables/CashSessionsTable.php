<?php

namespace App\Filament\Resources\Cash\Tables;

use App\Filament\Resources\Cash\Actions\PrintCashSessionAction;
use App\Filament\Resources\Cash\Actions\ReconcileCashSessionAction;
use App\Models\CashSession;
use App\Services\Cash\CashSessionService;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tabla de sesiones de caja.
 *
 * Columnas principales: estado (abierta/cerrada), sucursal, cajero que abrió,
 * monto de apertura, monto físico al cierre y descuadre (resaltado si != 0).
 *
 * Filtros: estado (tri-estado), sucursal, cajero. La fecha se filtra vía
 * sorteo por `opened_at` por default (últimas sesiones arriba).
 */
class CashSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('isOpen')
                    ->label('')
                    ->getStateUsing(fn ($record): bool => $record->isOpen())
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-open')
                    ->trueColor('success')
                    ->falseIcon('heroicon-o-lock-closed')
                    ->falseColor('gray')
                    ->tooltip(fn ($record): string => $record->isOpen() ? 'Sesión abierta' : 'Sesión cerrada'),

                TextColumn::make('establishment.name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('openedBy.name')
                    ->label('Abrió')
                    ->icon('heroicon-o-user')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('opened_at')
                    ->label('Apertura')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('opening_amount')
                    ->label('Monto inicial')
                    ->money('HNL')
                    ->alignEnd(),

                TextColumn::make('closed_at')
                    ->label('Cierre')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('actual_closing_amount')
                    ->label('Contado')
                    ->money('HNL')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('expected_closing_amount')
                    ->label('Esperado')
                    ->money('HNL')
                    ->alignEnd()
                    ->placeholder('—')
                    ->toggleable(),

                // Discrepancy con color según signo — sobra (verde), falta (rojo), cuadrada (gris).
                TextColumn::make('discrepancy')
                    ->label('Descuadre')
                    ->money('HNL')
                    ->alignEnd()
                    ->placeholder('—')
                    ->color(function ($state): string {
                        if ($state === null) {
                            return 'gray';
                        }
                        $value = (float) $state;
                        if ($value === 0.0) {
                            return 'success';
                        }

                        return $value > 0 ? 'warning' : 'danger';
                    })
                    ->weight('bold'),

                TextColumn::make('closedBy.name')
                    ->label('Cerró')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('authorizedBy.name')
                    ->label('Autorizó descuadre')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('opened_at', 'desc')
            ->filters([
                // Tri-estado: "Abiertas" → whereNull; "Cerradas" → whereNotNull; "Todas" → sin filtro.
                // Preferido sobre TernaryFilter porque los labels "abierta/cerrada" son más
                // legibles que "sí/no" para este dominio.
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        'open'   => 'Abiertas',
                        'closed' => 'Cerradas',
                    ])
                    ->placeholder('Todas')
                    ->query(function (Builder $query, array $data): Builder {
                        if ($data['value'] === 'open') {
                            return $query->whereNull('closed_at');
                        }
                        if ($data['value'] === 'closed') {
                            return $query->whereNotNull('closed_at');
                        }

                        return $query;
                    }),

                SelectFilter::make('establishment_id')
                    ->label('Sucursal')
                    ->relationship('establishment', 'name', fn ($query) => $query->where('is_active', true))
                    ->searchable()
                    ->preload()
                    ->placeholder('Todas'),

                SelectFilter::make('opened_by_user_id')
                    ->label('Cajero')
                    ->relationship('openedBy', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Todos'),

                // Descuadres: ayuda al contador a encontrar sesiones problemáticas
                // sin tener que hacer scroll. `!= 0` y no `is null` porque sesiones
                // abiertas no tienen discrepancy aún.
                Filter::make('con_descuadre')
                    ->label('Solo con descuadre')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('discrepancy')
                        ->where('discrepancy', '!=', 0)
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                PrintCashSessionAction::make(),

                // Conciliar — solo aparece cuando la sesión fue auto-cerrada por
                // el sistema y aún espera el conteo físico tardío. La visibilidad
                // se enforce en el resolver: si la sesión no está pendiente, el
                // resolver retorna null y la action queda oculta.
                //
                // El service se resuelve via `app()` porque la tabla no tiene DI
                // por método como las Pages. Es el único lugar del módulo donde
                // hacemos service location, y está acotado a esta línea — el
                // resto del flujo (handle, schema) recibe el service por parámetro.
                ReconcileCashSessionAction::make(
                    sessionResolver: fn (?CashSession $record = null): ?CashSession => $record !== null
                        && $record->isClosed()
                        && $record->isPendingReconciliation()
                            ? $record
                            : null,
                    cashSessions: app(CashSessionService::class),
                ),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Suppliers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon('heroicon-o-building-storefront'),
                TextColumn::make('rtn')
                    ->label('RTN')
                    ->searchable()
                    ->formatStateUsing(fn ($record) => $record->formatted_rtn)
                    ->copyable()
                    ->badge()
                    ->color('gray'),
                TextColumn::make('contact_name')
                    ->label('Contacto')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('phone')
                    ->label('Teléfono')
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('city')
                    ->label('Ciudad')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                // Columna Crédito ocultada hasta que el módulo de Cuentas por Pagar esté
                // implementado. Mientras tanto todos los proveedores son contado y mostrar
                // "Contado" repetido no aporta. La definición se queda comentada en código
                // para reactivar de un solo cuando se construya CxP.
                // TextColumn::make('credit_days')
                //     ->label('Crédito')
                //     ->formatStateUsing(fn (int $state) => $state === 0 ? 'Contado' : "{$state} días")
                //     ->badge()
                //     ->color(fn (int $state) => $state === 0 ? 'gray' : 'warning')
                //     ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('Activo')
                    ->onColor('success')
                    ->offColor('danger'),
                TextColumn::make('createdBy.name')
                    ->label('Creado por')
                    ->placeholder('Sistema')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name', 'asc')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),
                SelectFilter::make('city')
                    ->label('Ciudad')
                    ->options(fn () => \App\Models\Supplier::query()
                        ->whereNotNull('city')
                        ->distinct()
                        ->pluck('city', 'city')
                        ->toArray()
                    )
                    ->searchable()
                    ->placeholder('Todas'),
                // Filtro Crédito ocultado mientras el módulo de Cuentas por Pagar esté
                // pendiente. Reactivar junto con la columna comentada arriba cuando CxP
                // esté implementado.
                // TernaryFilter::make('credit_days')
                //     ->label('Crédito')
                //     ->placeholder('Todos')
                //     ->trueLabel('Con crédito')
                //     ->falseLabel('Solo contado')
                //     ->queries(
                //         true: fn ($query) => $query->where('credit_days', '>', 0),
                //         false: fn ($query) => $query->where('credit_days', 0),
                //     ),
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

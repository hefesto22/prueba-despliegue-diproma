<?php

namespace App\Filament\Resources\CaiRanges\Tables;

use App\Models\CaiRange;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CaiRangesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cai')
                    ->label('CAI')
                    ->limit(20)
                    ->copyable()
                    ->searchable(),

                TextColumn::make('prefix')
                    ->label('Prefijo')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('formatted_range')
                    ->label('Rango')
                    ->size('sm')
                    ->color('gray'),

                TextColumn::make('remaining')
                    ->label('Restantes')
                    ->badge()
                    ->color(fn (CaiRange $record): string => match (true) {
                        $record->isExhausted() => 'danger',
                        $record->isNearExhaustion() => 'warning',
                        default => 'success',
                    }),

                TextColumn::make('expiration_date')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->color(fn (CaiRange $record): string => match (true) {
                        $record->isExpired() => 'danger',
                        $record->days_until_expiration <= 30 => 'warning',
                        default => 'gray',
                    }),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),

                TextColumn::make('usage_percentage')
                    ->label('Uso')
                    ->suffix('%')
                    ->color(fn (CaiRange $record): string => match (true) {
                        $record->usage_percentage >= 90 => 'danger',
                        $record->usage_percentage >= 70 => 'warning',
                        default => 'gray',
                    }),
            ])
            ->actions([
                EditAction::make(),
                \Filament\Actions\Action::make('activate')
                    ->label('Activar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Activar CAI')
                    ->modalDescription('Al activar este CAI, se desactivarán los demás del mismo tipo de documento.')
                    ->action(fn (CaiRange $record) => $record->activate())
                    ->visible(fn (CaiRange $record): bool => ! $record->is_active && ! $record->isExpired() && ! $record->isExhausted()),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

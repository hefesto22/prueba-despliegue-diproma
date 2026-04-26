<?php

namespace App\Filament\Resources\IsvRetentionsReceived\Tables;

use App\Enums\IsvRetentionType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Filament\Forms\Components\Select;

class IsvRetentionsReceivedTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period_label')
                    ->label('Período')
                    ->state(fn ($record) => $record->periodLabel())
                    ->sortable(
                        query: fn ($query, $direction) => $query
                            ->orderBy('period_year', $direction)
                            ->orderBy('period_month', $direction)
                    )
                    ->icon('heroicon-o-calendar')
                    ->weight('medium'),

                TextColumn::make('retention_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (IsvRetentionType $state) => $state->shortLabel())
                    ->color(fn (IsvRetentionType $state) => match ($state) {
                        IsvRetentionType::TarjetasCreditoDebito => 'info',
                        IsvRetentionType::VentasEstado          => 'warning',
                        IsvRetentionType::Acuerdo215_2010       => 'success',
                    })
                    ->sortable(),

                TextColumn::make('agent_name')
                    ->label('Agente retenedor')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('agent_rtn')
                    ->label('RTN')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('document_number')
                    ->label('# Constancia')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->alignEnd(),

                TextColumn::make('establishment.name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('document_path')
                    ->label('Archivo')
                    ->formatStateUsing(fn (?string $state) => $state ? 'Adjunto' : '—')
                    ->badge()
                    ->color(fn (?string $state) => $state ? 'success' : 'gray')
                    ->toggleable(),

                TextColumn::make('createdBy.name')
                    ->label('Creado por')
                    ->placeholder('Sistema')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('period_year', 'desc')
            ->filters([
                // Filtro combinado año+mes: una sola fila en el UI, dos columnas
                // aplicadas en el query. Evita que el contador tenga que coordinar
                // 2 filtros sueltos para aislar un período.
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
                            ->when($data['period_year'] ?? null, fn ($q, $y) => $q->where('period_year', $y))
                            ->when($data['period_month'] ?? null, fn ($q, $m) => $q->where('period_month', $m));
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

                SelectFilter::make('retention_type')
                    ->label('Tipo')
                    ->options(IsvRetentionType::options())
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

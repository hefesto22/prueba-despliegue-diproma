<?php

namespace App\Filament\Resources\Repairs\Tables;

use App\Filament\Resources\Repairs\Actions\RepairTransitionActions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RepairsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('repair_number')
                    ->label('No.')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('received_at')
                    ->label('Recibido')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->searchable()
                    ->limit(30)
                    ->description(fn ($record) => $record->customer_phone),
                IconColumn::make('deviceCategory.icon')
                    ->label('Tipo')
                    ->icon(fn ($record) => $record->deviceCategory?->icon ?? 'heroicon-o-question-mark-circle')
                    ->tooltip(fn ($record) => $record->deviceCategory?->name),
                TextColumn::make('device_brand')
                    ->label('Marca / Modelo')
                    ->formatStateUsing(fn ($record) => trim(($record->device_brand ?? '') . ' ' . ($record->device_model ?? '')))
                    ->searchable(['device_brand', 'device_model'])
                    ->limit(30),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
                TextColumn::make('technician.name')
                    ->label('Técnico')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('items_count')
                    ->label('Líneas')
                    ->numeric()
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable(),
                TextColumn::make('advance_payment')
                    ->label('Anticipo')
                    ->money('HNL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('received_at', 'desc')
            // Sin filters[] — tabs cubren el filtro por estado. Si Mauricio
            // pide filtros adicionales (técnico, categoría) los agrego después
            // de validar que los tabs solos cargan limpio.
            ->recordActions([
                Action::make('print_quotation')
                    ->label('Imprimir')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->openUrlInNewTab()
                    ->url(fn ($record) => route('repairs.quotation.print', ['repair' => $record->qr_token])),

                // Acciones primarias del flujo como botones directos.
                // El `visible()` de cada una hace que solo aparezca la que
                // aplica al estado actual. UX: el cajero/técnico ve la
                // siguiente acción esperada sin abrir menú.
                ...RepairTransitionActions::primary(),

                // Anular oculto en "más opciones" — es excepcional y queremos
                // evitar clicks accidentales en el listado.
                ActionGroup::make([
                    ...RepairTransitionActions::secondary(),
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                    RestoreAction::make(),
                    ForceDeleteAction::make(),
                ])
                    ->label('Más')
                    ->icon('heroicon-o-ellipsis-vertical')
                    ->color('gray')
                    ->dropdown(),
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

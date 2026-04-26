<?php

namespace App\Filament\Resources\IsvRetentionsReceived\Schemas;

use App\Enums\IsvRetentionType;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class IsvRetentionReceivedInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([

                Section::make('Retención ISV recibida')
                    ->aside()
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('period_label')
                                ->label('Período')
                                ->state(fn ($record) => $record->periodLabel())
                                ->icon('heroicon-o-calendar')
                                ->weight('bold'),
                            TextEntry::make('retention_type')
                                ->label('Tipo')
                                ->badge()
                                ->formatStateUsing(fn (IsvRetentionType $state) => $state->label())
                                ->color(fn (IsvRetentionType $state) => match ($state) {
                                    IsvRetentionType::TarjetasCreditoDebito => 'info',
                                    IsvRetentionType::VentasEstado          => 'warning',
                                    IsvRetentionType::Acuerdo215_2010       => 'success',
                                }),
                            TextEntry::make('establishment.name')
                                ->label('Sucursal')
                                ->icon('heroicon-o-building-storefront')
                                ->placeholder('Sin sucursal'),
                        ]),
                        Grid::make(1)->schema([
                            TextEntry::make('retention_type_casilla')
                                ->label('Casilla SIISAR (Formulario 201)')
                                ->state(fn ($record) => $record->retention_type->siisarCasilla())
                                ->icon('heroicon-o-document-text'),
                        ]),
                    ]),

                Section::make('Agente retenedor')
                    ->aside()
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('agent_rtn')
                                ->label('RTN')
                                ->copyable()
                                ->fontFamily('mono'),
                            TextEntry::make('agent_name')
                                ->label('Nombre'),
                        ]),
                    ]),

                Section::make('Constancia y monto')
                    ->aside()
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('document_number')
                                ->label('# Constancia')
                                ->copyable()
                                ->placeholder('Sin número'),
                            TextEntry::make('amount')
                                ->label('Monto retenido')
                                ->money('HNL')
                                ->weight('bold'),
                            TextEntry::make('document_path')
                                ->label('Archivo')
                                ->formatStateUsing(fn (?string $state) => $state ? 'Archivo adjunto' : 'Sin archivo')
                                ->badge()
                                ->color(fn (?string $state) => $state ? 'success' : 'gray'),
                        ]),
                    ]),

                Section::make('Notas')
                    ->aside()
                    ->visible(fn ($record) => filled($record->notes))
                    ->schema([
                        TextEntry::make('notes')
                            ->label('')
                            ->placeholder('—'),
                    ]),

                Section::make('Auditoría')
                    ->aside()
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('createdBy.name')
                                ->label('Registrada por')
                                ->placeholder('Sistema'),
                            TextEntry::make('created_at')
                                ->label('Fecha de registro')
                                ->dateTime('d/m/Y H:i'),
                        ]),
                    ]),
            ]);
    }
}

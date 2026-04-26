<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class SupplierInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Identificación')
                    ->aside()
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('name')
                                ->label('Nombre comercial')
                                ->weight('bold'),
                            TextEntry::make('rtn')
                                ->label('RTN')
                                ->formatStateUsing(fn ($record) => $record->formatted_rtn)
                                ->copyable(),
                            TextEntry::make('company_name')
                                ->label('Razón social')
                                ->placeholder('—'),
                        ]),
                    ]),

                Section::make('Contacto')
                    ->aside()
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('contact_name')
                                ->label('Persona de contacto')
                                ->placeholder('—'),
                            TextEntry::make('email')
                                ->label('Correo')
                                ->copyable()
                                ->placeholder('—'),
                            TextEntry::make('phone')
                                ->label('Teléfono principal')
                                ->copyable()
                                ->placeholder('—'),
                            TextEntry::make('phone_secondary')
                                ->label('Teléfono secundario')
                                ->placeholder('—'),
                        ]),
                    ]),

                Section::make('Ubicación')
                    ->aside()
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('address')
                                ->label('Dirección')
                                ->placeholder('—'),
                            TextEntry::make('city')
                                ->label('Ciudad')
                                ->placeholder('—'),
                            TextEntry::make('department')
                                ->label('Departamento')
                                ->placeholder('—'),
                        ]),
                    ]),

                Section::make('Estado')
                    ->aside()
                    ->schema([
                        Grid::make(2)->schema([
                            // credit_days entry oculta hasta que el módulo de Cuentas por Pagar
                            // esté implementado. Cuando se reactive, restaurar la entry y volver
                            // el Grid a 3 columnas.
                            // TextEntry::make('credit_days')
                            //     ->label('Días de crédito')
                            //     ->formatStateUsing(fn (int $state) => $state === 0 ? 'Contado' : "{$state} días")
                            //     ->badge()
                            //     ->color(fn (int $state) => $state === 0 ? 'gray' : 'warning'),
                            IconEntry::make('is_active')
                                ->label('Estado')
                                ->boolean(),
                            TextEntry::make('createdBy.name')
                                ->label('Creado por')
                                ->placeholder('Sistema'),
                        ]),
                    ]),

                Section::make('Notas')
                    ->aside()
                    ->schema([
                        TextEntry::make('notes')
                            ->label('')
                            ->placeholder('Sin notas')
                            ->markdown(),
                    ])
                    ->collapsible(),
            ]);
    }
}

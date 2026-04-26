<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Identificación')
                    ->aside()
                    ->description('Datos principales del proveedor.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Nombre comercial')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('Ej: TecnoHN'),
                            TextInput::make('rtn')
                                ->label('RTN')
                                ->required()
                                ->maxLength(14)
                                ->unique(ignoreRecord: true)
                                ->placeholder('08011999123456')
                                ->helperText('14 dígitos, sin guiones'),
                        ]),
                        TextInput::make('company_name')
                            ->label('Razón social')
                            ->maxLength(255)
                            ->placeholder('Solo si difiere del nombre comercial'),
                    ]),

                Section::make('Contacto')
                    ->aside()
                    ->description('Información de contacto del proveedor.')
                    ->schema([
                        TextInput::make('contact_name')
                            ->label('Persona de contacto')
                            ->maxLength(255)
                            ->placeholder('Nombre del contacto principal'),
                        Grid::make(2)->schema([
                            TextInput::make('email')
                                ->label('Correo electrónico')
                                ->email()
                                ->maxLength(255)
                                ->placeholder('correo@proveedor.com'),
                            TextInput::make('phone')
                                ->label('Teléfono principal')
                                ->tel()
                                ->maxLength(20)
                                ->placeholder('+504 9999-9999'),
                        ]),
                        TextInput::make('phone_secondary')
                            ->label('Teléfono secundario')
                            ->tel()
                            ->maxLength(20)
                            ->placeholder('Opcional'),
                    ]),

                Section::make('Ubicación')
                    ->aside()
                    ->description('Dirección del proveedor.')
                    ->schema([
                        TextInput::make('address')
                            ->label('Dirección')
                            ->maxLength(500)
                            ->placeholder('Dirección completa'),
                        Grid::make(2)->schema([
                            TextInput::make('city')
                                ->label('Ciudad')
                                ->maxLength(100)
                                ->placeholder('Ej: Tegucigalpa'),
                            Select::make('department')
                                ->label('Departamento')
                                ->options([
                                    'Atlántida' => 'Atlántida',
                                    'Colón' => 'Colón',
                                    'Comayagua' => 'Comayagua',
                                    'Copán' => 'Copán',
                                    'Cortés' => 'Cortés',
                                    'Choluteca' => 'Choluteca',
                                    'El Paraíso' => 'El Paraíso',
                                    'Francisco Morazán' => 'Francisco Morazán',
                                    'Gracias a Dios' => 'Gracias a Dios',
                                    'Intibucá' => 'Intibucá',
                                    'Islas de la Bahía' => 'Islas de la Bahía',
                                    'La Paz' => 'La Paz',
                                    'Lempira' => 'Lempira',
                                    'Ocotepeque' => 'Ocotepeque',
                                    'Olancho' => 'Olancho',
                                    'Santa Bárbara' => 'Santa Bárbara',
                                    'Valle' => 'Valle',
                                    'Yoro' => 'Yoro',
                                ])
                                ->searchable()
                                ->placeholder('Seleccionar'),
                        ]),
                    ]),

                Section::make('Condiciones comerciales')
                    ->aside()
                    ->description('Estado y notas.')
                    ->schema([
                        // credit_days oculto y forzado a 0 hasta que el módulo de Cuentas
                        // por Pagar esté implementado. El campo permanece en BD como base
                        // del futuro flujo de crédito a proveedores; cuando se construya
                        // CxP, se reemplaza este Hidden por el TextInput original (ver
                        // SupplierForm en historial git, commit del fix de credit_days).
                        Hidden::make('credit_days')
                            ->default(0)
                            ->dehydrated(),
                        Toggle::make('is_active')
                            ->label('Activo')
                            ->default(true)
                            ->onColor('success')
                            ->offColor('danger'),
                        Textarea::make('notes')
                            ->label('Notas')
                            ->rows(3)
                            ->maxLength(2000)
                            ->placeholder('Notas internas sobre el proveedor'),
                    ]),
            ]);
    }
}

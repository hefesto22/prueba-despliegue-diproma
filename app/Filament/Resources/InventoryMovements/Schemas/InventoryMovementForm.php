<?php

namespace App\Filament\Resources\InventoryMovements\Schemas;

use App\Enums\MovementType;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class InventoryMovementForm
{
    /**
     * Formulario para ajustes manuales de inventario.
     * Solo permite AjusteEntrada y AjusteSalida — los automáticos
     * los genera PurchaseService internamente.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ajuste de Inventario')
                ->description('Registrar una corrección manual de stock (conteo físico, merma, daño, etc.)')
                ->icon('heroicon-o-adjustments-horizontal')
                ->columns(2)
                ->schema([
                    Select::make('product_id')
                        ->label('Producto')
                        ->relationship('product', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn ($state, $set) => $set('_stock_info', $state)),

                    Select::make('type')
                        ->label('Tipo de Ajuste')
                        ->options([
                            MovementType::AjusteEntrada->value => MovementType::AjusteEntrada->getLabel(),
                            MovementType::AjusteSalida->value => MovementType::AjusteSalida->getLabel(),
                        ])
                        ->required()
                        ->native(false),

                    TextInput::make('quantity')
                        ->label('Cantidad')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->integer(),

                    Placeholder::make('stock_actual')
                        ->label('Stock Actual')
                        ->content(function ($get) {
                            $productId = $get('product_id');
                            if (! $productId) {
                                return 'Seleccione un producto';
                            }
                            $product = \App\Models\Product::find($productId);
                            return $product ? "{$product->stock} unidades" : '—';
                        }),
                ]),

            Section::make('Razón del Ajuste')
                ->schema([
                    Textarea::make('notes')
                        ->label('Notas / Justificación')
                        ->placeholder('Ej: Conteo físico reveló diferencia, Producto dañado en bodega, Devolución de cliente...')
                        ->required()
                        ->rows(3)
                        ->maxLength(1000),
                ]),
        ]);
    }
}

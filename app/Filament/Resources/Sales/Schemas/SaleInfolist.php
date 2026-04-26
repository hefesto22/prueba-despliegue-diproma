<?php

namespace App\Filament\Resources\Sales\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SaleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // ─── Encabezado ──────────────────────────────
            Section::make('Datos de la Venta')
                ->icon('heroicon-o-shopping-bag')
                ->columns(3)
                ->schema([
                    TextEntry::make('sale_number')
                        ->label('Número de Venta')
                        ->weight('bold')
                        ->size('lg')
                        ->copyable(),
                    TextEntry::make('date')
                        ->label('Fecha')
                        ->date('d/m/Y'),
                    TextEntry::make('status')
                        ->label('Estado')
                        ->badge(),
                ]),

            // ─── Cliente ─────────────────────────────────
            Section::make('Cliente')
                ->icon('heroicon-o-user')
                ->columns(3)
                ->schema([
                    TextEntry::make('customer_name')
                        ->label('Nombre'),
                    TextEntry::make('customer_rtn')
                        ->label('RTN')
                        ->placeholder('Consumidor Final'),
                    TextEntry::make('customer.phone')
                        ->label('Teléfono')
                        ->placeholder('—'),
                ]),

            // ─── Items de la venta ───────────────────────
            Section::make('Detalle de Productos')
                ->icon('heroicon-o-cube')
                ->schema([
                    RepeatableEntry::make('items')
                        ->label('')
                        ->columns(5)
                        ->schema([
                            TextEntry::make('product.name')
                                ->label('Producto'),
                            TextEntry::make('quantity')
                                ->label('Cantidad')
                                ->alignCenter(),
                            TextEntry::make('unit_price')
                                ->label('Precio Unit.')
                                ->money('HNL'),
                            TextEntry::make('total')
                                ->label('Total Línea')
                                ->money('HNL')
                                ->weight('bold'),
                            TextEntry::make('tax_type')
                                ->label('Impuesto')
                                ->badge(),
                        ]),
                ]),

            // ─── Totales fiscales ────────────────────────
            Section::make('Resumen Fiscal')
                ->icon('heroicon-o-calculator')
                ->columns(2)
                ->schema([
                    Group::make([
                        TextEntry::make('subtotal')
                            ->label('Base Gravada')
                            ->money('HNL'),
                        TextEntry::make('isv')
                            ->label('ISV (15%)')
                            ->money('HNL'),
                    ]),
                    Group::make([
                        TextEntry::make('discount_amount')
                            ->label('Descuento')
                            ->money('HNL')
                            ->placeholder('Sin descuento')
                            ->color('danger'),
                        TextEntry::make('total')
                            ->label('TOTAL')
                            ->money('HNL')
                            ->weight('bold')
                            ->size('lg')
                            ->color('success'),
                    ]),
                ]),

            // ─── Notas y auditoría ───────────────────────
            Section::make('Información Adicional')
                ->columns(3)
                ->collapsed()
                ->schema([
                    TextEntry::make('notes')
                        ->label('Notas')
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('createdBy.name')
                        ->label('Vendedor')
                        ->placeholder('Sistema'),
                    TextEntry::make('created_at')
                        ->label('Creado')
                        ->dateTime('d/m/Y H:i'),
                    TextEntry::make('updated_at')
                        ->label('Actualizado')
                        ->dateTime('d/m/Y H:i'),
                ]),
        ]);
    }
}

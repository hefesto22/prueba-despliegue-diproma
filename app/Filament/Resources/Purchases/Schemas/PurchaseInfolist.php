<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Enums\PurchaseStatus;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class PurchaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Compra')
                    ->aside()
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('purchase_number')
                                ->label('# Compra')
                                ->weight('bold')
                                ->copyable(),
                            TextEntry::make('supplier.name')
                                ->label('Proveedor'),
                            TextEntry::make('establishment.name')
                                ->label('Sucursal')
                                ->placeholder('—')
                                ->icon('heroicon-o-building-storefront'),
                            TextEntry::make('date')
                                ->label('Fecha')
                                ->date('d/m/Y'),
                        ]),
                        // Crédito y vencimiento: visibles solo cuando la compra realmente
                        // tiene crédito (credit_days > 0). Mientras el módulo de Cuentas por
                        // Pagar esté pendiente de implementación, todas las compras nuevas
                        // son contado y estos entries quedan invisibles. Las dejamos en código
                        // porque cuando se reactive crédito vuelven a aparecer automáticamente
                        // sin tocar este archivo.
                        Grid::make(4)
                            ->visible(fn ($record) => $record->credit_days > 0)
                            ->schema([
                                TextEntry::make('status')
                                    ->label('Estado')
                                    ->badge(),
                                // Pago oculto en Borrador — ver comentario en PurchasesTable.
                                TextEntry::make('payment_status')
                                    ->label('Pago')
                                    ->badge()
                                    ->placeholder('—')
                                    ->getStateUsing(fn ($record) => $record->status === PurchaseStatus::Borrador
                                        ? null
                                        : $record->payment_status),
                                TextEntry::make('credit_days')
                                    ->label('Crédito')
                                    ->formatStateUsing(fn (int $state) => $state === 0 ? 'Contado' : "{$state} días"),
                                TextEntry::make('due_date')
                                    ->label('Vencimiento')
                                    ->date('d/m/Y')
                                    ->placeholder('N/A')
                                    ->color(fn ($record) => $record->isOverdue() ? 'danger' : null),
                            ]),
                        // Variante simplificada: solo Estado + Pago, sin crédito ni vencimiento.
                        // Aplica a las compras de contado (la mayoría hoy, todas mientras CxP no
                        // esté implementado). Cuando se reactive crédito, este Grid de 2 puede
                        // quedar como está — el de 4 solo aparecerá cuando haya crédito real.
                        Grid::make(2)
                            ->visible(fn ($record) => $record->credit_days === 0)
                            ->schema([
                                TextEntry::make('status')
                                    ->label('Estado')
                                    ->badge(),
                                // Pago oculto en Borrador — ver comentario en PurchasesTable.
                                TextEntry::make('payment_status')
                                    ->label('Pago')
                                    ->badge()
                                    ->placeholder('—')
                                    ->getStateUsing(fn ($record) => $record->status === PurchaseStatus::Borrador
                                        ? null
                                        : $record->payment_status),
                            ]),
                        Grid::make(1)->schema([
                            TextEntry::make('createdBy.name')
                                ->label('Registrada por')
                                ->placeholder('Sistema'),
                        ]),
                    ]),

                Section::make('Documento fiscal del proveedor')
                    ->aside()
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('document_type')
                                ->label('Tipo')
                                ->badge()
                                ->placeholder('N/A'),
                            TextEntry::make('supplier_invoice_number')
                                ->label('# documento')
                                ->copyable()
                                ->placeholder('N/A'),
                            TextEntry::make('supplier_cai')
                                ->label('CAI')
                                ->copyable()
                                ->fontFamily('mono')
                                ->placeholder('N/A'),
                        ]),
                    ]),

                Section::make('Productos')
                    ->aside()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                Grid::make(5)->schema([
                                    TextEntry::make('product.name')
                                        ->label('Producto')
                                        ->columnSpan(2),
                                    TextEntry::make('quantity')
                                        ->label('Cantidad'),
                                    TextEntry::make('unit_cost')
                                        ->label('Costo c/u')
                                        ->money('HNL'),
                                    TextEntry::make('total')
                                        ->label('Total')
                                        ->money('HNL')
                                        ->weight('bold'),
                                ]),
                            ]),
                    ]),

                Section::make('Totales')
                    ->aside()
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('subtotal')
                                ->label('Subtotal (sin ISV)')
                                ->money('HNL'),
                            TextEntry::make('isv')
                                ->label('ISV (crédito fiscal)')
                                ->money('HNL')
                                ->color('warning'),
                            TextEntry::make('total')
                                ->label('Total')
                                ->money('HNL')
                                ->weight('bold')
                                ->size('lg'),
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
                    ->collapsible()
                    ->visible(fn ($record) => filled($record->notes)),
            ]);
    }
}

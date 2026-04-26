<?php

namespace App\Filament\Resources\Purchases\Tables;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PurchasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('purchase_number')
                    ->label('# Compra')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon('heroicon-o-shopping-cart'),
                TextColumn::make('supplier.name')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('establishment.name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('document_type')
                    ->label('Tipo')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('supplier_invoice_number')
                    ->label('# Proveedor')
                    ->searchable()
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->sortable(),
                // Pago: oculto en Borrador. Una compra en Borrador todavía no se
                // ejecutó (no afectó stock, no actualizó costo) — decir que está
                // "Pagada" o "Pendiente" en ese estado es prematuro. El badge
                // aparece solo cuando la compra ya tiene una historia operativa
                // real (Confirmada o Anulada). El payment_status se resuelve en
                // PurchaseService::confirm() — ver comentario allí.
                TextColumn::make('payment_status')
                    ->label('Pago')
                    ->badge()
                    ->placeholder('—')
                    ->getStateUsing(fn ($record) => $record->status === PurchaseStatus::Borrador
                        ? null
                        : $record->payment_status)
                    ->sortable(),
                // Vencimiento: oculta por defecto mientras el módulo de Cuentas por Pagar
                // (crédito a proveedores) esté pendiente de implementación. Toda compra hoy
                // se registra al contado, así que esta columna no aporta información útil
                // — solo confunde con "Contado" repetido. Cuando se implemente CxP se
                // revierte a `toggleable()` (visible por defecto, ocultable por usuario).
                TextColumn::make('due_date')
                    ->label('Vencimiento')
                    ->date('d/m/Y')
                    ->placeholder('Contado')
                    ->color(fn ($record) => $record->isOverdue() ? 'danger' : null)
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ->defaultSort('date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(PurchaseStatus::class)
                    ->placeholder('Todos'),
                SelectFilter::make('payment_status')
                    ->label('Pago')
                    ->options(PaymentStatus::class)
                    ->placeholder('Todos'),
                SelectFilter::make('supplier_id')
                    ->label('Proveedor')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Todos'),
                SelectFilter::make('establishment_id')
                    ->label('Sucursal')
                    ->relationship('establishment', 'name', fn ($query) => $query->where('is_active', true))
                    ->searchable()
                    ->preload()
                    ->placeholder('Todas'),
                SelectFilter::make('document_type')
                    ->label('Tipo documento')
                    ->options(SupplierDocumentType::class)
                    ->placeholder('Todos'),
                TrashedFilter::make()
                    ->label('Eliminadas'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn ($record) => $record->isEditable()),
                DeleteAction::make()
                    ->visible(fn ($record) => $record->isEditable()),
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

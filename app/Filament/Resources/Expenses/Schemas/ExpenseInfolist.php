<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Infolist de Expense — vista de solo lectura para auditoría.
 *
 * Estructura paralela al form: estructura primero, fiscales después,
 * cierre con vínculo a CashMovement (si aplicó) y bloque de auditoría.
 *
 * El bloque "Vinculación con caja" solo aparece si payment_method =
 * Efectivo. Para los demás métodos se omite — sin movimiento de kardex
 * no hay sesión de caja a la que apuntar.
 */
class ExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([

                // ── Resumen del gasto ────────────────────────────────
                Section::make('Resumen')
                    ->aside()
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('expense_date')
                                ->label('Fecha')
                                ->date('d/m/Y')
                                ->icon('heroicon-o-calendar')
                                ->weight('bold'),

                            TextEntry::make('amount_total')
                                ->label('Monto total')
                                ->money('HNL')
                                ->weight('bold'),

                            TextEntry::make('category')
                                ->label('Categoría')
                                ->badge()
                                ->formatStateUsing(fn (ExpenseCategory $state) => $state->getLabel())
                                ->color(fn (ExpenseCategory $state) => $state->getColor())
                                ->icon(fn (ExpenseCategory $state) => $state->getIcon()),
                        ]),

                        Grid::make(3)->schema([
                            TextEntry::make('payment_method')
                                ->label('Método de pago')
                                ->badge()
                                ->formatStateUsing(fn (PaymentMethod $state) => $state->getLabel())
                                ->color(fn (PaymentMethod $state) => $state->getColor())
                                ->icon(fn (PaymentMethod $state) => $state->getIcon()),

                            TextEntry::make('establishment.name')
                                ->label('Sucursal')
                                ->icon('heroicon-o-building-storefront')
                                ->placeholder('—'),

                            TextEntry::make('user.name')
                                ->label('Registrado por')
                                ->icon('heroicon-o-user')
                                ->placeholder('—'),
                        ]),

                        TextEntry::make('description')
                            ->label('Descripción')
                            ->columnSpanFull(),
                    ]),

                // ── Datos fiscales del proveedor ─────────────────────
                Section::make('Datos fiscales del proveedor')
                    ->aside()
                    ->description('Información para sustento del crédito fiscal ante SAR.')
                    ->visible(fn (Expense $record): bool => filled($record->provider_name)
                        || filled($record->provider_rtn)
                        || filled($record->provider_invoice_number)
                        || filled($record->provider_invoice_cai))
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('provider_name')
                                ->label('Proveedor')
                                ->placeholder('—'),

                            TextEntry::make('provider_rtn')
                                ->label('RTN')
                                ->copyable()
                                ->fontFamily('mono')
                                ->placeholder('—'),
                        ]),

                        Grid::make(3)->schema([
                            TextEntry::make('provider_invoice_number')
                                ->label('# Factura')
                                ->copyable()
                                ->placeholder('—'),

                            TextEntry::make('provider_invoice_date')
                                ->label('Fecha factura')
                                ->date('d/m/Y')
                                ->placeholder('—'),

                            TextEntry::make('isv_amount')
                                ->label('ISV desglosado')
                                ->money('HNL')
                                ->placeholder('—'),
                        ]),

                        TextEntry::make('provider_invoice_cai')
                            ->label('CAI del proveedor')
                            ->copyable()
                            ->fontFamily('mono')
                            ->placeholder('—'),

                        IconEntry::make('is_isv_deductible')
                            ->label('Deducible de ISV')
                            ->boolean()
                            ->trueIcon('heroicon-o-check-circle')
                            ->falseIcon('heroicon-o-x-circle')
                            ->trueColor('success')
                            ->falseColor('gray'),
                    ]),

                // ── Vinculación con caja (solo si Efectivo) ──────────
                Section::make('Movimiento de caja')
                    ->aside()
                    ->description('Vínculo con el kardex físico de la caja chica.')
                    ->visible(fn (Expense $record): bool => $record->affectsCashBalance()
                        && $record->cashMovement !== null)
                    ->schema([
                        TextEntry::make('cashMovement.cash_session_id')
                            ->label('Sesión de caja')
                            ->state(fn (Expense $record) => $record->cashMovement?->cash_session_id)
                            ->formatStateUsing(fn (?int $state) => $state ? "Sesión #{$state}" : '—')
                            ->icon('heroicon-o-archive-box')
                            ->placeholder('—'),
                    ]),

                // ── Auditoría ────────────────────────────────────────
                Section::make('Auditoría')
                    ->aside()
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('created_at')
                                ->label('Registrado el')
                                ->dateTime('d/m/Y H:i'),

                            TextEntry::make('updated_at')
                                ->label('Última edición')
                                ->dateTime('d/m/Y H:i'),
                        ]),
                    ]),
            ]);
    }
}

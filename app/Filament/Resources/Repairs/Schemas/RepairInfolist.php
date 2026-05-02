<?php

namespace App\Filament\Resources\Repairs\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * Vista de detalle (read-only) de una Reparación.
 *
 * Igual que el form de creación, usa Tabs para mantener consistencia visual:
 * Cliente / Equipo / Diagnóstico / Totales. Las líneas de cotización y las
 * fotos se ven en sus RelationManagers (debajo de las pestañas, no aquí —
 * evitamos duplicar datos).
 */
class RepairInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // ─── Header con número, estado y fechas clave ────────────────
            // Fuera de las tabs porque es información de identificación que
            // siempre debe estar visible al abrir la vista.
            Section::make()
                ->columns(4)
                ->schema([
                    TextEntry::make('repair_number')
                        ->label('Número')
                        ->weight('bold')
                        ->copyable(),
                    TextEntry::make('status')
                        ->label('Estado')
                        ->badge(),
                    TextEntry::make('received_at')
                        ->label('Recibido')
                        ->dateTime('d/m/Y H:i'),
                    TextEntry::make('total')
                        ->label('Total')
                        ->money('HNL')
                        ->weight('bold'),
                ]),

            Tabs::make('Detalle')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Cliente')
                        ->icon('heroicon-o-user')
                        ->schema([
                            TextEntry::make('customer_name')
                                ->label('Nombre'),
                            TextEntry::make('customer_phone')
                                ->label('Teléfono')
                                ->copyable(),
                            TextEntry::make('customer_rtn')
                                ->label('RTN')
                                ->placeholder('Consumidor final')
                                ->copyable(),
                            TextEntry::make('customer.name')
                                ->label('Cliente registrado')
                                ->placeholder('Walk-in (sin registro previo)')
                                ->url(fn ($record) => $record->customer_id
                                    ? route('filament.admin.resources.customers.edit', ['record' => $record->customer_id])
                                    : null
                                ),
                        ])
                        ->columns(2),

                    Tab::make('Equipo')
                        ->icon('heroicon-o-computer-desktop')
                        ->schema([
                            TextEntry::make('deviceCategory.name')
                                ->label('Tipo de equipo'),
                            TextEntry::make('device_brand')
                                ->label('Marca'),
                            TextEntry::make('device_model')
                                ->label('Modelo')
                                ->placeholder('—'),
                            TextEntry::make('device_serial')
                                ->label('Número de serie')
                                ->placeholder('—')
                                ->copyable(),
                            TextEntry::make('reported_issue')
                                ->label('Falla reportada por el cliente')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),

                    Tab::make('Diagnóstico')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->badge(fn ($record) => $record?->diagnosis ? null : 'Pendiente')
                        ->badgeColor('warning')
                        ->schema([
                            TextEntry::make('technician.name')
                                ->label('Técnico asignado')
                                ->placeholder('Sin asignar'),
                            TextEntry::make('createdBy.name')
                                ->label('Recibido por')
                                ->placeholder('—'),
                            TextEntry::make('diagnosis')
                                ->label('Diagnóstico técnico')
                                ->placeholder('Pendiente')
                                ->columnSpanFull(),
                            TextEntry::make('internal_notes')
                                ->label('Notas internas')
                                ->placeholder('—')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),

                    Tab::make('Totales y fechas')
                        ->icon('heroicon-o-calculator')
                        ->schema([
                            TextEntry::make('exempt_total')
                                ->label('Subtotal exento')
                                ->money('HNL'),
                            TextEntry::make('taxable_total')
                                ->label('Subtotal gravado')
                                ->money('HNL'),
                            TextEntry::make('isv')
                                ->label('ISV 15%')
                                ->money('HNL'),
                            TextEntry::make('total')
                                ->label('Total')
                                ->money('HNL')
                                ->weight('bold'),
                            TextEntry::make('advance_payment')
                                ->label('Anticipo cobrado')
                                ->money('HNL')
                                ->placeholder('—'),
                            TextEntry::make('quoted_at')
                                ->label('Cotizado')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('—'),
                            TextEntry::make('approved_at')
                                ->label('Aprobado')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('—'),
                            TextEntry::make('completed_at')
                                ->label('Completado')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('—'),
                            TextEntry::make('delivered_at')
                                ->label('Entregado')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('—'),
                        ])
                        ->columns(3),

                    Tab::make('Notas para el cliente')
                        ->icon('heroicon-o-chat-bubble-bottom-center-text')
                        ->visible(fn ($record) => filled($record?->notes))
                        ->schema([
                            TextEntry::make('notes')
                                ->label('Notas')
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }
}

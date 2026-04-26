<?php

namespace App\Filament\Resources\FiscalPeriods\Tables;

use App\Exports\PurchaseBook\PurchaseBookExport;
use App\Exports\SalesBook\SalesBookExport;
use App\Models\Establishment;
use App\Models\FiscalPeriod;
use App\Services\FiscalBooks\PurchaseBookService;
use App\Services\FiscalBooks\SalesBookService;
use App\Services\FiscalPeriods\FiscalPeriodService;
use App\Services\FiscalPeriods\Exceptions\FiscalPeriodException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class FiscalPeriodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period_label')
                    ->label('Período')
                    ->weight('bold'),

                TextColumn::make('period_year')
                    ->label('Año')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('period_month')
                    ->label('Mes')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Estado derivado (no columna en DB, calculado en PHP).
                // Orden por declared_at como proxy razonable.
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (FiscalPeriod $record): string => match (true) {
                        $record->isOpen() && $record->wasReopened() => 'Reabierto',
                        $record->isOpen() => 'Abierto',
                        default => 'Declarado',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Declarado' => 'success',
                        'Reabierto' => 'warning',
                        'Abierto'   => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'Declarado' => 'heroicon-o-check-circle',
                        'Reabierto' => 'heroicon-o-arrow-path',
                        'Abierto'   => 'heroicon-o-clock',
                    }),

                TextColumn::make('declared_at')
                    ->label('Declarado')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('declaredBy.name')
                    ->label('Por')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('reopened_at')
                    ->label('Reabierto')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reopenedBy.name')
                    ->label('Reabierto por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reopen_reason')
                    ->label('Motivo reapertura')
                    ->limit(40)
                    ->tooltip(fn (FiscalPeriod $record): ?string => $record->reopen_reason)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('declaration_notes')
                    ->label('Notas')
                    ->limit(40)
                    ->tooltip(fn (FiscalPeriod $record): ?string => $record->declaration_notes)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('solo_abiertos')
                    ->label('Solo períodos abiertos')
                    ->query(fn (Builder $query): Builder => $query->open()),

                Filter::make('solo_declarados')
                    ->label('Solo períodos declarados')
                    ->query(fn (Builder $query): Builder => $query->closed()),
            ])
            ->actions([
                // ─── Descargar Libro de Ventas SAR ─────────────
                // Disponible siempre que el usuario pueda ver el módulo. No depende del estado
                // del período — el contador puede necesitar reimprimirlo incluso años después
                // para conciliaciones o auditorías.
                Action::make('libro_ventas')
                    ->label('Libro de Ventas')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => auth()->user()?->can('viewAny', FiscalPeriod::class) === true)
                    // Selector de sucursal opcional: por defecto el libro es
                    // company-wide (así lo declara el contador al SAR). El filtro
                    // por sucursal es útil para conciliaciones internas o para
                    // cuadrar por punto de venta antes de consolidar.
                    ->schema([
                        Select::make('establishment_id')
                            ->label('Sucursal')
                            ->options(fn () => Establishment::query()
                                ->active()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->placeholder('Todas las sucursales (company-wide)')
                            ->helperText('Opcional. Déjelo en blanco para incluir todas las sucursales — es el modo en que se declara al SAR.')
                            ->native(false),
                    ])
                    ->action(function (FiscalPeriod $record, array $data, SalesBookService $service) {
                        try {
                            $establishmentId = isset($data['establishment_id'])
                                ? (int) $data['establishment_id']
                                : null;

                            $book   = $service->build(
                                $record->period_year,
                                $record->period_month,
                                $establishmentId,
                            );
                            $export = new SalesBookExport($book);

                            return Excel::download($export, $export->fileName());
                        } catch (\InvalidArgumentException $e) {
                            // Caso defensivo: período con year/month corruptos en DB.
                            // Registros legítimos no disparan esto porque la migración los valida.
                            Notification::make()
                                ->title('No se pudo generar el Libro de Ventas')
                                ->body("Período inválido: {$e->getMessage()}")
                                ->danger()
                                ->persistent()
                                ->send();
                        } catch (\Throwable $e) {
                            report($e);
                            Notification::make()
                                ->title('Error generando el Libro de Ventas')
                                ->body('Ocurrió un error inesperado. El equipo técnico fue notificado.')
                                ->danger()
                                ->persistent()
                                ->send();
                        }

                        return null;
                    }),

                // ─── Descargar Libro de Compras SAR ────────────
                // Misma lógica de visibilidad y errores que el Libro de Ventas:
                // disponible siempre que el usuario pueda ver el módulo; se permite
                // incluso para períodos ya declarados (reimpresión para auditorías).
                //
                // No se comparte una sola acción con Ventas porque la declaración
                // ISV-353 tiene dos formularios separados (débito fiscal / crédito
                // fiscal) y el contador descarga cada libro por separado para
                // cuadrar cada sección de forma independiente.
                Action::make('libro_compras')
                    ->label('Libro de Compras')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => auth()->user()?->can('viewAny', FiscalPeriod::class) === true)
                    // Mismo criterio que Libro de Ventas: filtro por sucursal
                    // opcional, default company-wide (declaración SAR).
                    ->schema([
                        Select::make('establishment_id')
                            ->label('Sucursal')
                            ->options(fn () => Establishment::query()
                                ->active()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->placeholder('Todas las sucursales (company-wide)')
                            ->helperText('Opcional. Déjelo en blanco para incluir todas las sucursales — es el modo en que se declara al SAR.')
                            ->native(false),
                    ])
                    ->action(function (FiscalPeriod $record, array $data, PurchaseBookService $service) {
                        try {
                            $establishmentId = isset($data['establishment_id'])
                                ? (int) $data['establishment_id']
                                : null;

                            $book   = $service->build(
                                $record->period_year,
                                $record->period_month,
                                $establishmentId,
                            );
                            $export = new PurchaseBookExport($book);

                            return Excel::download($export, $export->fileName());
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()
                                ->title('No se pudo generar el Libro de Compras')
                                ->body("Período inválido: {$e->getMessage()}")
                                ->danger()
                                ->persistent()
                                ->send();
                        } catch (\Throwable $e) {
                            report($e);
                            Notification::make()
                                ->title('Error generando el Libro de Compras')
                                ->body('Ocurrió un error inesperado. El equipo técnico fue notificado.')
                                ->danger()
                                ->persistent()
                                ->send();
                        }

                        return null;
                    }),

                // ─── Declarar ──────────────────────────────────
                Action::make('declarar')
                    ->label('Declarar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (FiscalPeriod $record): bool =>
                        $record->isOpen()
                        && auth()->user()?->can('declare', $record) === true
                    )
                    ->requiresConfirmation()
                    ->modalHeading(fn (FiscalPeriod $record): string =>
                        "Declarar período {$record->period_label} al SAR"
                    )
                    ->modalDescription(
                        'Al declarar, este período se considera CERRADO: las facturas de este mes '
                        . 'ya NO se podrán anular, solo corregir por Nota de Crédito. '
                        . 'Esta acción queda registrada con su usuario y fecha.'
                    )
                    ->modalSubmitActionLabel('Sí, declarar al SAR')
                    ->schema([
                        Textarea::make('declaration_notes')
                            ->label('Notas de la declaración')
                            ->placeholder('Ej. N° de acuse SAR, observaciones del contador, etc.')
                            ->rows(3)
                            ->maxLength(1000)
                            ->helperText('Opcional. Útil para auditoría: número de acuse o referencia SAR.'),
                    ])
                    ->action(function (FiscalPeriod $record, array $data, FiscalPeriodService $service): void {
                        try {
                            $service->declare(
                                period: $record,
                                declaredBy: auth()->user(),
                                notes: $data['declaration_notes'] ?? null,
                            );

                            Notification::make()
                                ->title('Período declarado')
                                ->body("El período {$record->period_label} quedó marcado como declarado al SAR.")
                                ->success()
                                ->send();
                        } catch (FiscalPeriodException $e) {
                            Notification::make()
                                ->title('No se pudo declarar el período')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                // ─── Reabrir ──────────────────────────────────
                Action::make('reabrir')
                    ->label('Reabrir')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (FiscalPeriod $record): bool =>
                        $record->isClosed()
                        && auth()->user()?->can('reopen', $record) === true
                    )
                    ->requiresConfirmation()
                    ->modalHeading(fn (FiscalPeriod $record): string =>
                        "Reabrir período {$record->period_label} (declaración rectificativa)"
                    )
                    ->modalDescription(
                        'Solo use esta acción si debe presentar una declaración rectificativa al SAR '
                        . '(Acuerdo 189-2014). Quedará registro de quién, cuándo y por qué se reabrió. '
                        . 'Después de corregir, debe volver a declarar el período.'
                    )
                    ->modalSubmitActionLabel('Sí, reabrir período')
                    ->schema([
                        Textarea::make('reopen_reason')
                            ->label('Motivo de la reapertura')
                            ->placeholder('Ej. Error de cálculo ISV detectado en factura 001-001-01-00000012; se requiere declaración rectificativa.')
                            ->required()
                            ->rows(4)
                            ->minLength(20)
                            ->maxLength(1000)
                            ->helperText('Obligatorio. Este texto queda en el rastro de auditoría.'),
                    ])
                    ->action(function (FiscalPeriod $record, array $data, FiscalPeriodService $service): void {
                        try {
                            $service->reopen(
                                period: $record,
                                reopenedBy: auth()->user(),
                                reason: $data['reopen_reason'],
                            );

                            Notification::make()
                                ->title('Período reabierto')
                                ->body("El período {$record->period_label} fue reabierto. Presente la declaración rectificativa y vuelva a declarar.")
                                ->warning()
                                ->send();
                        } catch (FiscalPeriodException $e) {
                            Notification::make()
                                ->title('No se pudo reabrir el período')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),
            ])
            // Ordenar por (año desc, mes desc) — Filament v4 no soporta múltiples
            // defaultSort, así que aplicamos el secondary sort via modifyQueryUsing.
            ->modifyQueryUsing(fn (Builder $query): Builder =>
                $query->orderBy('period_year', 'desc')->orderBy('period_month', 'desc')
            )
            ->striped()
            ->paginated([25, 50, 100]);
    }
}

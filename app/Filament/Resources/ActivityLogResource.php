<?php

namespace App\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Spatie\Activitylog\Models\Activity;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'Registro de Actividad';

    protected static ?string $pluralModelLabel = 'Registros de Actividad';

    protected static ?int $navigationSort = 99;

    public static function getNavigationGroup(): ?string
    {
        return 'Administración';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['causer']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('log_name')
                    ->label('Tipo')
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descripción')
                    ->searchable()
                    ->limit(50),
                TextColumn::make('subject_type')
                    ->label('Modelo')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('causer.name')
                    ->label('Realizado por')
                    ->placeholder('Sistema')
                    ->searchable()
                    ->icon('heroicon-o-user'),
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Tipo de log')
                    ->options(fn () => Cache::remember(
                        'activity_log_types',
                        now()->addMinutes(10),
                        fn () => Activity::distinct()->pluck('log_name', 'log_name')->toArray()
                    )),
                SelectFilter::make('subject_type')
                    ->label('Modelo')
                    ->options(fn () => Cache::remember(
                        'activity_subject_types',
                        now()->addMinutes(10),
                        fn () => Activity::distinct()
                            ->whereNotNull('subject_type')
                            ->pluck('subject_type')
                            ->mapWithKeys(fn ($type) => [$type => class_basename($type)])
                            ->toArray()
                    )),
                Filter::make('created_at')
                    ->indicateUsing(function (array $data): ?string {
                        if ($data['from'] ?? null) {
                            return 'Desde: ' . $data['from'];
                        }
                        return null;
                    })
                    ->schema([
                        DatePicker::make('from')->label('Desde'),
                        DatePicker::make('until')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalle de Actividad')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('log_name')
                                ->label('Tipo de log')
                                ->badge()
                                ->color('primary'),
                            TextEntry::make('description')
                                ->label('Descripción'),
                            TextEntry::make('subject_type')
                                ->label('Modelo afectado')
                                ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—'),
                            TextEntry::make('subject_id')
                                ->label('ID del registro'),
                            TextEntry::make('causer.name')
                                ->label('Realizado por')
                                ->placeholder('Sistema'),
                            TextEntry::make('created_at')
                                ->label('Fecha y hora')
                                ->dateTime('d/m/Y H:i:s'),
                        ]),
                    ]),

                Section::make('Cambios Realizados')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->collapsible()
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('properties.old')
                                ->label('Valores anteriores')
                                ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '—')
                                ->markdown()
                                ->placeholder('Sin datos anteriores'),
                            TextEntry::make('properties.attributes')
                                ->label('Valores nuevos')
                                ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '—')
                                ->markdown()
                                ->placeholder('Sin datos nuevos'),
                        ]),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\ActivityLogResource\Pages\ListActivityLogs::route('/'),
            'view' => \App\Filament\Resources\ActivityLogResource\Pages\ViewActivityLog::route('/{record}'),
        ];
    }
}
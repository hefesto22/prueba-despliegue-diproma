<?php

namespace App\Filament\Resources\CaiRanges\Schemas;

use App\Enums\DocumentType;
use App\Models\CompanySetting;
use App\Models\Establishment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CaiRangeForm
{
    public static function configure(Schema $schema): Schema
    {
        $company = CompanySetting::current();

        return $schema->components([
            Section::make('Datos del CAI')
                ->description('Información de la autorización emitida por SAR')
                ->icon('heroicon-o-document-text')
                ->columns(2)
                ->schema([
                    TextInput::make('cai')
                        ->label('Código CAI')
                        ->placeholder('XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX')
                        ->required()
                        ->maxLength(50)
                        ->columnSpanFull(),

                    DatePicker::make('authorization_date')
                        ->label('Fecha de Autorización')
                        ->required()
                        ->native(false),

                    DatePicker::make('expiration_date')
                        ->label('Fecha Límite de Emisión')
                        ->required()
                        ->native(false)
                        ->after('authorization_date'),

                    Select::make('document_type')
                        ->label('Tipo de Documento')
                        ->options(collect(DocumentType::cases())
                            ->mapWithKeys(fn (DocumentType $dt) => [
                                $dt->value => $dt->getLabelWithCode(),
                            ])
                            ->toArray())
                        ->default(DocumentType::Factura->value)
                        ->required()
                        ->native(false)
                        ->selectablePlaceholder(false)
                        ->helperText('Código SAR según Acuerdo 481-2017. Cada CAI autoriza un solo tipo.')
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, callable $get) {
                            // Re-sincronizar el prefix cuando cambia el tipo de documento:
                            // si hay establecimiento seleccionado, recomputar; si no,
                            // actualizar solo el segmento de tipo del prefix por defecto.
                            $establishmentId = $get('establishment_id');
                            if ($establishmentId && $establishment = Establishment::find($establishmentId)) {
                                $set('prefix', $establishment->fullPrefix($state));
                            }
                        }),
                ]),

            Section::make('Establecimiento')
                ->description('Dejar en blanco si el CAI es centralizado (aplica a cualquier establecimiento). Para Sistema por Sucursal, seleccionar el establecimiento específico.')
                ->icon('heroicon-o-building-office')
                ->schema([
                    Select::make('establishment_id')
                        ->label('Establecimiento')
                        ->options(fn () => Establishment::active()
                            ->where('company_setting_id', $company->id)
                            ->get()
                            ->mapWithKeys(fn ($e) => [$e->id => "{$e->name} ({$e->prefix})"])
                            ->toArray())
                        ->searchable()
                        ->nullable()
                        ->native(false)
                        ->helperText('Opcional: vincular a un establecimiento específico (Sistema por Sucursal)')
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, callable $get) {
                            if ($state && $establishment = Establishment::find($state)) {
                                // Usar el document_type seleccionado en el form — nunca hardcodear.
                                // Si el usuario aún no ha tocado el Select, $get() devuelve
                                // el default del Select ('01'), así que siempre hay un valor válido.
                                $documentType = $get('document_type') ?? DocumentType::Factura->value;
                                $set('prefix', $establishment->fullPrefix($documentType));
                            }
                        }),
                ]),

            Section::make('Rango Autorizado')
                ->description('Numeración correlativa autorizada por SAR para este CAI')
                ->icon('heroicon-o-hashtag')
                ->columns(3)
                ->schema([
                    TextInput::make('prefix')
                        ->label('Prefijo (Est-Punto-Tipo)')
                        ->default($company->invoice_prefix)
                        ->placeholder('001-001-01')
                        ->required()
                        ->maxLength(12)
                        ->helperText('Formato: XXX-XXX-XX. Se autocompleta al elegir establecimiento.'),

                    TextInput::make('range_start')
                        ->label('Número Inicial')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->placeholder('1'),

                    TextInput::make('range_end')
                        ->label('Número Final')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->placeholder('500')
                        ->gt('range_start'),

                    TextInput::make('current_number')
                        ->label('Número Actual')
                        ->numeric()
                        ->default(0)
                        ->helperText('Se llena automáticamente. Solo editar si migra datos existentes.')
                        ->dehydrateStateUsing(fn ($state, $get) => $state ?: ($get('range_start') - 1)),
                ]),

            Section::make('Estado')
                ->schema([
                    Toggle::make('is_active')
                        ->label('CAI Activo')
                        ->helperText('Al activar, se desactivan automáticamente los demás CAI del mismo tipo de documento.')
                        ->default(false),
                ]),
        ]);
    }
}

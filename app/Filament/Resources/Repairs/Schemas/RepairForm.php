<?php

namespace App\Filament\Resources\Repairs\Schemas;

use App\Models\Customer;
use App\Models\DeviceCategory;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Form de recepción de una Reparación.
 *
 * Tres pestañas (Tabs) que separan responsabilidades visuales:
 *
 *   1. Cliente — autocomplete sobre `customers` con auto-creación al guardar
 *      si trae RTN nuevo (lógica en CreateRepair::mutateFormDataBeforeCreate).
 *   2. Equipo — categoría + marca + modelo + serial + contraseña + falla.
 *   3. Diagnóstico técnico — opcional al recibir, técnico lo llena después.
 *
 * Las fotos viven en `RepairPhotosRelationManager` (pestaña aparte tras crear).
 */
class RepairForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Reparación')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Cliente')
                        ->icon('heroicon-o-user')
                        ->schema(self::clientFields()),

                    Tab::make('Equipo')
                        ->icon('heroicon-o-computer-desktop')
                        ->schema(self::deviceFields()),

                    Tab::make('Fotos del equipo')
                        ->icon('heroicon-o-camera')
                        ->visibleOn('create') // Solo al crear; en edit las gestiona el RelationManager
                        ->schema(self::photoFields()),

                    Tab::make('Diagnóstico técnico')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->badge(fn ($record) => $record?->diagnosis ? null : 'Pendiente')
                        ->badgeColor('warning')
                        ->schema(self::diagnosisFields()),
                ]),
        ]);
    }

    /**
     * Campos del cliente — autocomplete sobre `customers`.
     *
     * `live()` en el Select dispara `afterStateUpdated` para rellenar
     * los snapshot fields (name/phone/rtn) cuando se selecciona uno
     * existente. Si el cajero escribe datos sin seleccionar, los snapshot
     * fields quedan editables y CreateRepair autocrea el Customer al guardar.
     */
    private static function clientFields(): array
    {
        return [
            Select::make('customer_id')
                ->label('Buscar cliente')
                ->placeholder('Nombre, teléfono o RTN')
                ->searchable()
                ->getSearchResultsUsing(function (string $search): array {
                    return Customer::query()
                        ->active()
                        ->where(function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%")
                                ->orWhere('rtn', 'like', "%{$search}%");
                        })
                        ->orderBy('name')
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (Customer $c) => [
                            $c->id => $c->name . ' — ' . ($c->phone ?? 's/tel') . (filled($c->rtn) ? ' (' . $c->rtn . ')' : ''),
                        ])
                        ->toArray();
                })
                ->getOptionLabelUsing(function ($value): ?string {
                    $c = Customer::find($value);
                    return $c ? "{$c->name} — " . ($c->phone ?? 's/tel') : null;
                })
                ->afterStateUpdated(function ($state, Set $set) {
                    if ($state) {
                        $c = Customer::find($state);
                        if ($c) {
                            $set('customer_name', $c->name);
                            $set('customer_phone', $c->phone);
                            $set('customer_rtn', $c->rtn);
                        }
                    }
                })
                ->live()
                ->columnSpanFull()
                ->helperText('Empieza a escribir para buscar un cliente existente. Si es nuevo, déjalo en blanco y llena los datos abajo.'),

            TextInput::make('customer_name')
                ->label('Nombre')
                ->required()
                ->maxLength(200),
            TextInput::make('customer_phone')
                ->label('Teléfono')
                ->tel()
                ->required()
                ->maxLength(30),
            TextInput::make('customer_rtn')
                ->label('RTN (opcional)')
                ->placeholder('0801-1999-12345')
                ->maxLength(20)
                ->helperText('Solo si el cliente quiere factura con RTN'),
        ];
    }

    /**
     * Campos del equipo recibido.
     */
    private static function deviceFields(): array
    {
        return [
            Select::make('device_category_id')
                ->label('Tipo de equipo')
                ->required()
                ->options(fn () => DeviceCategory::active()->ordered()->pluck('name', 'id'))
                ->searchable()
                ->preload(),
            TextInput::make('device_brand')
                ->label('Marca')
                ->required()
                ->maxLength(80)
                ->placeholder('HP, Dell, Sony, Nintendo...'),
            TextInput::make('device_model')
                ->label('Modelo')
                ->maxLength(120)
                ->placeholder('Pavilion 15, PS5, Switch OLED...'),
            TextInput::make('device_serial')
                ->label('Número de serie')
                ->maxLength(120)
                ->helperText('Si el equipo lo tiene visible'),
            TextInput::make('device_password')
                ->label('Contraseña del equipo (opcional)')
                ->password()
                ->revealable()
                ->maxLength(120)
                ->helperText('Se guarda cifrada. Solo el técnico autenticado la ve.')
                ->columnSpanFull(),
            Textarea::make('reported_issue')
                ->label('Falla reportada por el cliente')
                ->required()
                ->rows(3)
                ->placeholder('Descripción tal como la dijo el cliente. Ej: "No prende", "se reinicia solo", "pantalla rota".')
                ->columnSpanFull(),
        ];
    }

    /**
     * Campos de fotos del equipo (solo en create).
     *
     * El cajero sube hasta 3 fotos al recibir el equipo. Se almacenan
     * temporalmente en `tmp/{uuid}` y `CreateRepair::afterCreate` las
     * procesa: convierte a WebP optimizado y crea registros RepairPhoto
     * vinculados al Repair recién creado.
     *
     * Después de creado, las fotos adicionales (durante reparación,
     * finalizada) se gestionan desde RepairPhotosRelationManager.
     */
    private static function photoFields(): array
    {
        return [
            FileUpload::make('upload_photos')
                ->label('Fotos del equipo (al recibir)')
                ->helperText('Hasta 3 fotos. Las imágenes se convierten automáticamente a WebP optimizado para ahorrar espacio.')
                ->image()
                ->multiple()
                ->maxFiles(3)
                ->maxSize(10240) // 10 MB por foto antes de convertir
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->directory('tmp/repair-photos') // temporal — afterCreate las mueve a repairs/{id}/
                ->disk('public')
                ->reorderable()
                ->imageEditor()
                ->columnSpanFull()
                ->dehydrated(true), // queda en form data para que afterCreate lo lea
        ];
    }

    /**
     * Campos de diagnóstico técnico.
     *
     * Opcionales al recibir. El técnico los completa tras la inspección.
     * El badge "Pendiente" en la tab se oculta automáticamente cuando se
     * llena el `diagnosis` (ver `Tabs::badge()` en el método `configure()`).
     */
    private static function diagnosisFields(): array
    {
        return [
            Select::make('technician_id')
                ->label('Técnico asignado')
                ->options(fn () => User::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->searchable()
                ->preload()
                ->placeholder('Sin asignar todavía'),
            Textarea::make('diagnosis')
                ->label('Diagnóstico')
                ->rows(4)
                ->placeholder('Qué encontraste, qué necesita reparación, observaciones técnicas.')
                ->columnSpanFull(),
            Textarea::make('internal_notes')
                ->label('Notas internas (no visibles al cliente)')
                ->rows(2)
                ->columnSpanFull(),
        ];
    }
}

<?php

namespace App\Filament\Resources\Repairs\Schemas;

use App\Enums\RepairItemCondition;
use App\Enums\RepairItemSource;
use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Schema compartido del formulario de una línea de cotización.
 *
 * Única fuente de verdad para los campos de RepairItem. Consumido por:
 *   - `RepairItemsRelationManager` (CRUD de líneas en el editor).
 *   - `RepairTransitionActions::cotizar()` (Repeater del modal
 *     "Marcar como Cotizado" — cotización rápida desde el listado).
 *
 * Extraído del relation manager para no duplicar la lógica condicional
 * (source → campos visibles, autollenado de precio desde Product, etc.)
 * en dos lugares (DRY). Los `Get`/`Set` usan rutas relativas, por lo que
 * funcionan igual dentro de un Repeater que en un form plano.
 */
class RepairItemSchema
{
    /**
     * Campos del form de línea.
     *
     * Dos presentaciones sobre la misma lógica:
     *   - Normal (`$compact = false`): layout 2 columnas con costo, notas y
     *     textos de ayuda — para el relation manager del editor.
     *   - Compacta (`$compact = true`): layout 3 columnas, sin costo ni
     *     notas ni helper texts — para el Repeater del modal "Marcar como
     *     Cotizado", donde el objetivo es cotizar con el mínimo de scroll.
     *     Costo y notas se pueden completar después desde el editor.
     *
     * @return array<int, \Filament\Schemas\Components\Component|\Filament\Forms\Components\Field>
     */
    public static function components(bool $compact = false): array
    {
        return [
            Select::make('source')
                ->label('Tipo de línea')
                ->required()
                ->options(collect(RepairItemSource::selectable())->mapWithKeys(
                    fn (RepairItemSource $s) => [$s->value => $s->getLabel()]
                ))
                ->live()
                ->afterStateUpdated(function ($state, Set $set) {
                    // Limpiar campos dependientes al cambiar tipo
                    $set('product_id', null);
                    $set('condition', null);
                    $set('external_supplier', null);
                    // Default descripción amigable
                    if ($state === RepairItemSource::HonorariosReparacion->value) {
                        $set('description', 'Honorarios por reparación');
                    } elseif ($state === RepairItemSource::HonorariosMantenimiento->value) {
                        $set('description', 'Honorarios por mantenimiento');
                    }
                })
                ->columnSpan($compact ? 2 : 'full'),

            ...$compact ? [
                TextInput::make('quantity')
                    ->label('Cantidad')
                    ->required()
                    ->numeric()
                    ->integer()
                    ->step(1)
                    ->default(1)
                    ->minValue(1),
            ] : [],

            // Solo cuando source = PiezaInventario
            Select::make('product_id')
                ->label('Producto del inventario')
                ->placeholder('Buscar por nombre, marca o SKU')
                ->searchable()
                ->visible(fn (Get $get) => $get('source') === RepairItemSource::PiezaInventario->value)
                ->required(fn (Get $get) => $get('source') === RepairItemSource::PiezaInventario->value)
                ->getSearchResultsUsing(function (string $search): array {
                    return Product::query()
                        ->where('is_active', true)
                        ->where(function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%")
                                ->orWhere('brand', 'like', "%{$search}%");
                        })
                        ->where('stock', '>', 0)
                        ->limit(20)
                        ->get()
                        // Mostrar precio público CON ISV — es el monto que el
                        // cliente paga y con el que el técnico debe cuadrar
                        // mentalmente. La BD guarda sale_price NETO; el
                        // accessor sale_price_with_isv reconstruye el
                        // precio con ISV (gravado: ×1.15, exento: igual).
                        ->mapWithKeys(fn (Product $p) => [
                            $p->id => "{$p->name} (stock: {$p->stock} | L. " . number_format($p->sale_price_with_isv, 2) . ')',
                        ])
                        ->toArray();
                })
                ->getOptionLabelUsing(fn ($value) => Product::find($value)?->name)
                ->afterStateUpdated(function ($state, Set $set) {
                    if ($state) {
                        $p = Product::find($state);
                        if ($p) {
                            $set('description', $p->name);
                            // unit_price: convención WITH ISV (igual que cart del POS
                            // y SaleItem). RepairQuotationService hace back-out por
                            // tax_type al persistir subtotal/isv_amount.
                            // unit_cost: convención NETA (igual que CPP del producto).
                            $set('unit_price', (string) $p->sale_price_with_isv);
                            $set('unit_cost', (string) $p->cost_price);
                        }
                    }
                })
                ->live()
                ->columnSpanFull(),

            // Solo cuando source = PiezaExterna
            Select::make('condition')
                ->label('Condición')
                ->options(collect(RepairItemCondition::cases())->mapWithKeys(
                    fn (RepairItemCondition $c) => [$c->value => $c->getLabel()]
                ))
                ->visible(fn (Get $get) => $get('source') === RepairItemSource::PiezaExterna->value)
                ->required(fn (Get $get) => $get('source') === RepairItemSource::PiezaExterna->value)
                ->live()
                ->helperText(fn (Get $get) => match ($get('condition')) {
                    'nueva' => 'Precio incluye 15% ISV',
                    'usada' => 'Exento de ISV',
                    default => null,
                }),
            TextInput::make('external_supplier')
                ->label('Comprado a (proveedor)')
                ->visible(fn (Get $get) => $get('source') === RepairItemSource::PiezaExterna->value)
                ->maxLength(200)
                ->placeholder('Nombre del local / proveedor'),

            // En compacto el costo solo aplica a pieza externa (inventario lo
            // autollenan del producto; honorarios no tienen costo). Va junto a
            // condición + proveedor para no agregar una fila extra.
            ...$compact ? [
                TextInput::make('unit_cost')
                    ->label('Costo (opcional)')
                    ->numeric()
                    ->step('0.01')
                    ->prefix('L.')
                    ->visible(fn (Get $get) => $get('source') === RepairItemSource::PiezaExterna->value),
            ] : [],

            TextInput::make('description')
                ->label('Descripción')
                ->required()
                ->maxLength(300)
                ->placeholder('Lo que verá el cliente en la factura. Ej: Pantalla 14" nueva')
                ->columnSpan($compact ? 2 : 'full'),

            ...$compact ? [] : [
                TextInput::make('quantity')
                    ->label('Cantidad')
                    ->required()
                    ->numeric()
                    ->integer()
                    ->step(1)
                    ->default(1)
                    ->minValue(1)
                    ->helperText('Cantidades enteras únicamente. Para "media hora" ajusta el precio unitario.'),
            ],
            TextInput::make('unit_price')
                ->label(fn (Get $get) => match (true) {
                    $get('source') === RepairItemSource::PiezaExterna->value && $get('condition') === RepairItemCondition::Nueva->value => 'Precio unitario (con ISV)',
                    default => 'Precio unitario',
                })
                ->required()
                ->numeric()
                ->step('0.01')
                ->prefix('L.')
                ->minValue(0),

            // Costo y notas solo en el form completo del editor — en la
            // cotización rápida estorban (se completan después si aplica).
            ...$compact ? [] : [
                TextInput::make('unit_cost')
                    ->label('Costo (opcional)')
                    ->numeric()
                    ->step('0.01')
                    ->prefix('L.')
                    ->helperText('Lo que pagaste por la pieza. Solo para reportes internos.')
                    ->columnSpanFull(),

                Textarea::make('notes')
                    ->label('Notas')
                    ->rows(2)
                    ->columnSpanFull(),
            ],
        ];
    }

    /**
     * Normalizar el array del form antes de pasarlo al service.
     * Convierte strings en enums, garantiza tipos.
     */
    public static function normalize(array $data): array
    {
        if (isset($data['source']) && is_string($data['source'])) {
            $data['source'] = RepairItemSource::from($data['source']);
        }
        if (isset($data['condition']) && is_string($data['condition'])) {
            $data['condition'] = RepairItemCondition::from($data['condition']);
        }

        return $data;
    }
}

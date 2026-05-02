<?php

namespace App\Filament\Resources\Repairs\RelationManagers;

use App\Enums\RepairItemCondition;
use App\Enums\RepairItemSource;
use App\Models\Product;
use App\Services\Repairs\RepairQuotationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Líneas de cotización de la reparación.
 *
 * Cada CRUD de línea pasa por `RepairQuotationService`, que:
 *   - resuelve `tax_type` desde `source` + `condition`/`product_id`,
 *   - persiste el item con sus totales pre-calculados,
 *   - recalcula los totales del Repair padre (subtotal/exempt_total/
 *     taxable_total/isv/total).
 *
 * El form usa `live()` en `source` y `condition` para refrescar los
 * campos visibles según el tipo de línea (mano de obra → solo precio;
 * pieza externa → precio + nueva/usada + proveedor; pieza inventario
 * → selector de producto + cantidad).
 */
class RepairItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Cotización';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedListBullet;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
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
                        ->columnSpanFull(),

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
                                ->mapWithKeys(fn (Product $p) => [
                                    $p->id => "{$p->name} (stock: {$p->stock} | L. " . number_format($p->sale_price, 2) . ')',
                                ])
                                ->toArray();
                        })
                        ->getOptionLabelUsing(fn ($value) => Product::find($value)?->name)
                        ->afterStateUpdated(function ($state, Set $set) {
                            if ($state) {
                                $p = Product::find($state);
                                if ($p) {
                                    $set('description', $p->name);
                                    $set('unit_price', (string) $p->sale_price);
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

                    TextInput::make('description')
                        ->label('Descripción')
                        ->required()
                        ->maxLength(300)
                        ->columnSpanFull(),

                    TextInput::make('quantity')
                        ->label('Cantidad')
                        ->required()
                        ->numeric()
                        ->step('0.01')
                        ->default(1)
                        ->minValue(0.01),
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
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('source')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('description')
                    ->label('Descripción')
                    ->wrap()
                    ->limit(50),
                TextColumn::make('condition')
                    ->label('Cond.')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('quantity')
                    ->label('Cant.')
                    ->numeric(2),
                TextColumn::make('unit_price')
                    ->label('P. Unit.')
                    ->money('HNL'),
                TextColumn::make('tax_type')
                    ->label('Fiscal')
                    ->badge()
                    ->color(fn ($state) => match ($state?->value ?? $state) {
                        'gravado_15' => 'warning',
                        'exento' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('HNL'),
                TextColumn::make('isv_amount')
                    ->label('ISV')
                    ->money('HNL'),
                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->weight('bold'),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Tipo')
                    ->options(collect(RepairItemSource::cases())->mapWithKeys(
                        fn (RepairItemSource $s) => [$s->value => $s->getLabel()]
                    )),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Agregar línea')
                    ->icon('heroicon-o-plus')
                    ->using(function (array $data, RepairQuotationService $service) {
                        $data = $this->normalizeData($data);
                        return $service->addItem($this->getOwnerRecord(), $data);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function ($record, array $data, RepairQuotationService $service) {
                        $data = $this->normalizeData($data);
                        return $service->updateItem($record, $data);
                    }),
                DeleteAction::make()
                    ->using(function ($record, RepairQuotationService $service) {
                        $service->removeItem($record);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Normalizar el array del form antes de pasarlo al service.
     * Convierte strings en enums, limpia campos vacíos, garantiza tipos.
     */
    private function normalizeData(array $data): array
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

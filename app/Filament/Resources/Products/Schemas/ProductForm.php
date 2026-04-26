<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductCondition;
use App\Enums\ProductType;
use App\Enums\TaxType;
use App\Models\SpecOption;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ProductForm
{
    /**
     * Prefijo para campos de spec en el formulario.
     * Formato: spec_{tipoProducto}_{claveCampo}
     * Esto evita conflictos de state path entre tipos que comparten claves (processor, ram, etc.)
     */
    private static function specFieldName(ProductType $type, string $fieldKey): string
    {
        return "spec_{$type->value}_{$fieldKey}";
    }

    /**
     * Transformar datos del formulario (spec_tipo_campo) a specs JSON para BD.
     * Usar en mutateFormDataBeforeCreate / mutateFormDataBeforeSave.
     */
    public static function packSpecs(array $data): array
    {
        $typeValue = $data['product_type'] ?? null;
        if ($typeValue instanceof ProductType) {
            $typeValue = $typeValue->value;
        }

        $type = ProductType::tryFrom($typeValue ?? '');
        $specs = [];

        if ($type) {
            foreach ($type->specFields() as $field) {
                $formKey = static::specFieldName($type, $field['key']);
                $val = $data[$formKey] ?? null;
                if (filled($val)) {
                    $specs[$field['key']] = $val;
                }
            }
        }

        // Limpiar todos los campos spec_ del data
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'spec_')) {
                unset($data[$key]);
            }
        }

        $data['specs'] = $specs;
        return $data;
    }

    /**
     * Transformar specs JSON de BD a campos del formulario (spec_tipo_campo).
     * Usar en mutateFormDataBeforeFill.
     */
    public static function unpackSpecs(array $data): array
    {
        $typeValue = $data['product_type'] ?? null;
        if ($typeValue instanceof ProductType) {
            $typeValue = $typeValue->value;
        }

        $type = ProductType::tryFrom($typeValue ?? '');
        $specs = $data['specs'] ?? [];

        if ($type && is_array($specs)) {
            foreach ($type->specFields() as $field) {
                $formKey = static::specFieldName($type, $field['key']);
                $data[$formKey] = $specs[$field['key']] ?? null;
            }
        }

        return $data;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([

                // ── 1. Tipo de producto ──────────────────────────────
                Section::make('Tipo de producto')
                    ->aside()
                    ->description('Los campos de especificaciones se ajustan según el tipo.')
                    ->schema([
                        Select::make('product_type')
                            ->label('¿Qué estás registrando?')
                            ->options(ProductType::class)
                            ->required()
                            ->default(ProductType::Laptop)
                            ->live()
                            ->afterStateUpdated(function (callable $set) {
                                // Limpiar TODOS los campos spec de todos los tipos
                                foreach (ProductType::cases() as $t) {
                                    foreach ($t->specFields() as $field) {
                                        $set(static::specFieldName($t, $field['key']), null);
                                    }
                                }
                            }),
                    ]),

                // ── 2. Marca, modelo, SKU + specs dinámicos ──────────
                Section::make('Producto')
                    ->aside()
                    ->description(fn ($get) => static::productSectionDescription($get))
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('brand')
                                ->label('Marca')
                                ->maxLength(100)
                                ->placeholder(fn ($get) => static::isSimpleType($get)
                                    ? 'Opcional — dejar vacío si es genérico'
                                    : 'HP, DELL, SONY...')
                                ->helperText(fn ($get) => static::isSimpleType($get)
                                    ? 'No requerido para genéricos'
                                    : null)
                                ->dehydrateStateUsing(fn ($state) => filled($state) ? mb_strtoupper($state) : $state)
                                ->afterStateUpdated(fn (callable $set, $state) => $set('brand', filled($state) ? mb_strtoupper($state) : $state))
                                ->live(onBlur: true),
                            TextInput::make('model')
                                ->label('Modelo')
                                ->maxLength(100)
                                ->placeholder(fn ($get) => static::isSimpleType($get)
                                    ? 'Opcional'
                                    : 'PROBOOK 450 G10')
                                ->helperText(fn ($get) => static::isSimpleType($get)
                                    ? 'No requerido para genéricos'
                                    : null)
                                ->dehydrateStateUsing(fn ($state) => filled($state) ? mb_strtoupper($state) : $state)
                                ->afterStateUpdated(fn (callable $set, $state) => $set('model', filled($state) ? mb_strtoupper($state) : $state))
                                ->live(onBlur: true),
                        ]),

                        // Campos dinámicos por tipo (state paths ÚNICOS por tipo)
                        ...static::buildDynamicSpecFields(),

                        // Preview nombre autogenerado
                        Placeholder::make('name_preview')
                            ->label('')
                            ->content(function ($get) {
                                $type = static::getProductType($get);
                                if (!$type) {
                                    return new HtmlString('<span class="text-gray-400">Seleccione un tipo de producto</span>');
                                }

                                $brand = $get('brand') ?? '';
                                $specs = static::collectSpecs($get, $type);
                                $name = $type->generateName($brand, $get('model') ?? '', $specs);

                                // Preview del SKU
                                $skuPrefix = $type->skuPrefix();
                                $brandPrefix = filled($brand)
                                    ? strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $brand), 0, 3) ?: 'GEN')
                                    : 'GEN';
                                $skuPreview = "{$skuPrefix}-{$brandPrefix}-XXXXX";

                                return new HtmlString(
                                    "<div class='space-y-1'>"
                                    . "<div class='font-semibold text-base'>{$name}</div>"
                                    . "<div class='text-xs text-gray-500 dark:text-gray-400'>SKU: {$skuPreview} (se genera al guardar)</div>"
                                    . "</div>"
                                );
                            }),

                        // En edición: mostrar SKU existente como lectura
                        TextInput::make('sku')
                            ->label('SKU')
                            ->disabled()
                            ->dehydrated()
                            ->visible(fn (string $operation) => $operation === 'edit'),

                        Hidden::make('name')->dehydrated(),
                        Hidden::make('slug')->dehydrated(),
                    ]),

                // ── 3. Condición + Precios ───────────────────────────
                Section::make('Precio')
                    ->aside()
                    ->description(fn ($get) => static::isGravado($get)
                        ? 'Nuevo — ingrese precios con ISV incluido.'
                        : 'Usado — exento de ISV.')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('condition')
                                ->label('Condición')
                                ->options(ProductCondition::class)
                                ->required()
                                ->default(ProductCondition::New)
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set) {
                                    $isUsed = $state === ProductCondition::Used->value
                                        || $state === ProductCondition::Used;
                                    $set('tax_type', $isUsed
                                        ? TaxType::Exento->value
                                        : TaxType::Gravado15->value);
                                }),
                            TextInput::make('cost_price')
                                ->label('Costo')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->step(0.01)
                                ->prefix('L')
                                ->placeholder('0.00')
                                ->live(onBlur: true),
                            TextInput::make('sale_price')
                                ->label('Precio de venta')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->step(0.01)
                                ->prefix('L')
                                ->placeholder('0.00')
                                ->live(onBlur: true),
                        ]),
                        Placeholder::make('price_summary')
                            ->label('')
                            ->content(fn ($get) => static::buildPriceSummary($get))
                            ->visible(fn ($get) =>
                                (float) ($get('cost_price') ?? 0) > 0
                                || (float) ($get('sale_price') ?? 0) > 0),
                        Hidden::make('tax_type')
                            ->default(TaxType::Gravado15->value)
                            ->dehydrated(),
                    ]),

                // ── 4. Stock ─────────────────────────────────────────
                Section::make('Inventario')
                    ->aside()
                    ->description('Control de existencias.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('stock')
                                ->label('Cantidad en stock')
                                ->numeric()
                                ->default(0)
                                ->minValue(0),
                            TextInput::make('min_stock')
                                ->label('Alerta de stock mínimo')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->helperText('Te avisaremos cuando baje de aquí.'),
                        ]),
                    ]),

                // ── 5. Extras (colapsado) ────────────────────────────
                Section::make('Opcional')
                    ->aside()
                    ->description('Descripción, seriales, imagen.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Textarea::make('description')
                            ->label('Descripción')
                            ->rows(2)
                            ->maxLength(2000)
                            ->placeholder('Notas adicionales del producto'),
                        TagsInput::make('serial_numbers')
                            ->label('Números de serie')
                            ->placeholder('Escriba y presione Enter'),
                        FileUpload::make('image_path')
                            ->label('Imagen')
                            ->image()
                            ->directory('products')
                            ->maxSize(2048)
                            ->imageResizeMode('cover')
                            ->imageCropAspectRatio('1:1')
                            ->imageResizeTargetWidth('400')
                            ->imageResizeTargetHeight('400'),
                        Toggle::make('is_active')
                            ->label('Producto activo')
                            ->default(true)
                            ->onColor('success')
                            ->offColor('danger'),
                    ]),
            ]);
    }

    // ─── Dynamic spec fields ─────────────────────────────────

    /**
     * Renderizar campos dinámicos POR TIPO de producto.
     * Cada campo tiene un state path ÚNICO: spec_{tipo}_{campo}
     * Esto evita conflictos de Livewire/Filament entre campos de tipos distintos
     * que comparten la misma clave (processor, storage, connectivity, etc.)
     */
    private static function buildDynamicSpecFields(): array
    {
        $containers = [];

        foreach (ProductType::cases() as $type) {
            $typeFields = [];

            foreach ($type->specFields() as $field) {
                $fieldKey = $field['key'];
                $fieldType = $field['type'] ?? 'text';
                $formName = static::specFieldName($type, $fieldKey);

                if ($fieldType === 'select') {
                    $typeFields[] = Select::make($formName)
                        ->label($field['label'])
                        ->searchable()
                        ->options(fn () => SpecOption::searchOptions($fieldKey))
                        ->getSearchResultsUsing(function (string $search) use ($fieldKey): array {
                            $search = mb_strtoupper(trim($search));
                            $options = SpecOption::searchOptions($fieldKey, $search);

                            if (filled($search) && ! isset($options[$search])) {
                                $options = [$search => "{$search} (PERSONALIZADO)"] + $options;
                            }

                            return $options;
                        })
                        ->getOptionLabelUsing(fn (?string $value): ?string => $value)
                        ->live();
                } else {
                    $typeFields[] = TextInput::make($formName)
                        ->label($field['label'])
                        ->placeholder($field['placeholder'] ?? '')
                        ->maxLength(200)
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? mb_strtoupper($state) : $state)
                        ->live(onBlur: true);
                }
            }

            // Dividir en filas de 3 columnas
            $rows = array_chunk($typeFields, 3);
            foreach ($rows as $row) {
                $containers[] = Grid::make(3)
                    ->schema($row)
                    ->visible(function ($get) use ($type) {
                        $selected = $get('product_type');
                        if ($selected instanceof ProductType) {
                            $selected = $selected->value;
                        }
                        return $selected === $type->value;
                    });
            }
        }

        return $containers;
    }

    // ─── Helpers ─────────────────────────────────────────────

    private static function getProductType($get): ?ProductType
    {
        $val = $get('product_type');
        if ($val instanceof ProductType) {
            return $val;
        }
        return ProductType::tryFrom($val ?? '');
    }

    /**
     * Tipos "simples" donde marca/modelo son opcionales (genéricos).
     */
    private static function isSimpleType($get): bool
    {
        $type = static::getProductType($get);

        return $type && in_array($type, [
            ProductType::Accessory,
            ProductType::Component,
            ProductType::Printer,
        ]);
    }

    private static function productSectionDescription($get): string
    {
        $type = static::getProductType($get);
        if (! $type) {
            return 'Detalles';
        }

        return match ($type) {
            ProductType::Accessory, ProductType::Component =>
                $type->getLabel() . ' — solo el tipo es requerido, marca y modelo son opcionales.',
            ProductType::Printer =>
                $type->getLabel() . ' — marca opcional para genéricos.',
            default => $type->getLabel(),
        };
    }

    private static function isGravado($get): bool
    {
        $condition = $get('condition');
        return $condition !== ProductCondition::Used->value
            && $condition !== ProductCondition::Used;
    }

    /**
     * Recoger valores de specs desde los campos ÚNICOS del formulario.
     * Lee desde spec_{tipo}_{campo} y devuelve array [campo => valor].
     */
    private static function collectSpecs($get, ProductType $type): array
    {
        $specs = [];
        foreach ($type->specFields() as $field) {
            $formName = static::specFieldName($type, $field['key']);
            $val = $get($formName);
            if (filled($val)) {
                $specs[$field['key']] = $val;
            }
        }
        return $specs;
    }

    private static function buildPriceSummary($get): HtmlString
    {
        $cost = (float) ($get('cost_price') ?? 0);
        $sale = (float) ($get('sale_price') ?? 0);
        $gravado = static::isGravado($get);
        $multiplier = (float) config('tax.multiplier', 1.15);
        $parts = [];

        if ($gravado && $sale > 0) {
            $saleBase = round($sale / $multiplier, 2);
            $saleIsv = round($sale - $saleBase, 2);
            $parts[] = "<span class='text-gray-500 dark:text-gray-400'>Venta: L "
                . number_format($saleBase, 2) . " + ISV L " . number_format($saleIsv, 2) . "</span>";
        }

        if ($cost > 0 && $sale > 0) {
            $costBase = $gravado ? round($cost / $multiplier, 2) : $cost;
            $saleBase = $gravado ? round($sale / $multiplier, 2) : $sale;
            $profit = round($saleBase - $costBase, 2);
            $margin = $costBase > 0 ? round(($profit / $costBase) * 100, 2) : 0;
            $color = $margin >= 20 ? 'text-green-500' : ($margin >= 10 ? 'text-yellow-500' : 'text-red-500');
            $parts[] = "<span class='{$color} font-semibold'>Ganancia: L "
                . number_format($profit, 2) . " ({$margin}%)</span>";
        }

        return new HtmlString(
            empty($parts) ? '' : "<div class='text-sm'>" . implode(' &nbsp;·&nbsp; ', $parts) . "</div>"
        );
    }
}

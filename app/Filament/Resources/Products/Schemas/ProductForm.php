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

        // tryFrom case-insensitive: los valores guardados están en MAYÚSCULAS
        // (ej. 'LAPTOP'), los cases del enum en minúsculas (ej. 'laptop').
        $type = ProductType::tryFrom(mb_strtolower((string) ($typeValue ?? '')));
        $specs = [];

        if ($type) {
            // Tipo enum conocido: empacar specs específicos del tipo.
            foreach ($type->specFields() as $field) {
                $formKey = static::specFieldName($type, $field['key']);
                $val = $data[$formKey] ?? null;
                if (filled($val)) {
                    $specs[$field['key']] = $val;
                }
            }
        } else {
            // Tipo CUSTOM: el form puede haber puesto `subtype` directamente
            // en `data.specs.subtype` vía dot notation del Select. Preservarlo
            // — sin esto, $data['specs'] = $specs sobreescribe el subtype.
            $existingSpecs = $data['specs'] ?? [];
            if (is_array($existingSpecs) && filled($existingSpecs['subtype'] ?? null)) {
                $specs['subtype'] = $existingSpecs['subtype'];
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

        // Case-insensitive resolve (ver packSpecs).
        $type = ProductType::tryFrom(mb_strtolower((string) ($typeValue ?? '')));
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
                // Combina los 8 tipos del enum (con sus labels bonitos y
                // schema de specs específicos) + tipos custom que el cliente
                // haya agregado (Equipo de seguridad, Honorarios, etc).
                //
                // Si el cliente escribe un tipo que no existe en la lista,
                // aparece "(PERSONALIZADO)" y al guardar el producto queda
                // registrado en spec_options para el próximo. Mismo patrón
                // que RAM, procesador, almacenamiento.
                Section::make('Tipo de producto')
                    ->aside()
                    ->description('Si el tipo no existe en la lista, escribilo y se guarda automáticamente para próxima vez. Los campos de especificaciones se ajustan según el tipo.')
                    ->schema([
                        Select::make('product_type')
                            ->label('¿Qué estás registrando?')
                            ->required()
                            ->default(ProductType::Laptop->value)
                            ->searchable()
                            ->options(fn () => static::buildProductTypeOptions())
                            ->getSearchResultsUsing(function (string $search): array {
                                $base = static::buildProductTypeOptions();
                                $needle = mb_strtolower(trim($search));

                                if ($needle === '') {
                                    return $base;
                                }

                                // Filtrar opciones que coincidan con la búsqueda
                                // (case-insensitive en el label).
                                $filtered = array_filter(
                                    $base,
                                    fn (string $label) => str_contains(mb_strtolower($label), $needle),
                                );

                                // Si no hay match exacto y el search está lleno,
                                // ofrecer "(PERSONALIZADO)" — guardará el valor en
                                // MAYÚSCULAS al confirmar el producto.
                                $upper = mb_strtoupper(trim($search));
                                $existsAsEnum = ProductType::tryFrom(mb_strtolower(trim($search))) !== null;
                                $existsAsCustom = isset($base[$upper]);

                                if (filled($search) && ! $existsAsEnum && ! $existsAsCustom) {
                                    $filtered = [$upper => "{$upper} (PERSONALIZADO)"] + $filtered;
                                }

                                return $filtered;
                            })
                            ->getOptionLabelUsing(function (?string $value) {
                                if (! filled($value)) {
                                    return null;
                                }
                                // Si el valor es un enum case, devolver su label oficial.
                                $enum = ProductType::tryFrom(mb_strtolower((string) $value));
                                if ($enum) {
                                    return $enum->getLabel();
                                }
                                // Si es custom, mostrarlo tal cual (MAYÚSCULAS).
                                return $value;
                            })
                            ->live()
                            ->afterStateUpdated(function (callable $set) {
                                // Limpiar todos los campos spec al cambiar de tipo.
                                // Para tipos enum los specs aplicables son distintos;
                                // para custom no se muestran specs específicos.
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
                                $rawType = $get('product_type');
                                if (! filled($rawType)) {
                                    return new HtmlString('<span class="text-gray-400">Seleccione un tipo de producto</span>');
                                }

                                $type = static::getProductType($get); // ?ProductType
                                $brand = $get('brand') ?? '';
                                $model = $get('model') ?? '';

                                if ($type) {
                                    // Tipo enum conocido: nombre y SKU usan el enum.
                                    $specs = static::collectSpecs($get, $type);
                                    $name = $type->generateName($brand, $model, $specs);
                                    $skuPrefix = $type->skuPrefix();
                                } else {
                                    // Tipo personalizado: tipo + marca + modelo
                                    // + subtype (si está). Ej: "HONORARIOS - INSTALACIÓN".
                                    $parts = [mb_strtoupper((string) $rawType)];
                                    if (filled($brand)) $parts[] = mb_strtoupper((string) $brand);
                                    if (filled($model)) $parts[] = mb_strtoupper((string) $model);
                                    $name = implode(' ', $parts);

                                    $subtype = $get('specs.subtype');
                                    if (filled($subtype)) {
                                        $name .= ' - ' . mb_strtoupper((string) $subtype);
                                    }

                                    $clean = strtoupper(preg_replace('/[^a-zA-Z]/', '', (string) $rawType) ?: '');
                                    $skuPrefix = $clean !== '' ? substr($clean, 0, 3) : 'GEN';
                                }

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
                    ]),

                // ── 2.5. Datos del producto (SOLO tipos custom) ──────
                // Para tipos enum (Laptop, Desktop, etc.) los specs vienen
                // del schema del enum y se renderizan arriba dinámicamente.
                // Para tipos custom (Equipo de seguridad, Honorarios, etc.),
                // damos al cliente UN sub-clasificador (Subtipo) + un campo
                // libre de descripción técnica para que pueda identificar
                // el producto sin necesidad de configurar nuevos schemas.
                Section::make('Datos del producto')
                    ->aside()
                    ->description('Subtipo, descripción técnica y naturaleza (servicio o producto físico).')
                    ->visible(fn ($get) => static::isCustomType($get))
                    ->schema([
                        // Toggle is_service: identifica si este tipo custom es
                        // un servicio (sin inventario) o un producto físico.
                        // Esto controla:
                        //   - Si se muestra/oculta la sección "Inventario".
                        //   - Si se muestra/oculta el campo "Condición".
                        //   - Si el POS permite editar el precio en el carrito.
                        //   - Si se descuenta stock al vender.
                        //   - Si aparece en reportes de "stock bajo".
                        //
                        // Default false: ante la duda, asumimos que es producto
                        // físico — más seguro porque no oculta el inventario.
                        Toggle::make('is_service')
                            ->label('Es un servicio (sin inventario)')
                            ->helperText('Marcar SI: Honorarios profesionales, instalación, mantenimiento, asesoría. NO marcar para productos físicos como equipos de seguridad, cámaras, biométricos.')
                            ->default(false)
                            ->onColor('warning')
                            ->offColor('success')
                            ->live(),

                        Select::make('specs.subtype')
                            ->label('Subtipo')
                            ->searchable()
                            ->options(fn () => SpecOption::searchOptions('subtype'))
                            ->getSearchResultsUsing(function (string $search): array {
                                $search = mb_strtoupper(trim($search));
                                $options = SpecOption::searchOptions('subtype', $search);

                                if (filled($search) && ! isset($options[$search])) {
                                    $options = [$search => "{$search} (PERSONALIZADO)"] + $options;
                                }

                                return $options;
                            })
                            ->getOptionLabelUsing(fn (?string $value): ?string => $value)
                            ->helperText('Ej: Cámara IP, DVR, Biométrico, Instalación. Si no existe, escribilo y se guarda.')
                            ->live(),

                        Textarea::make('description')
                            ->label('Descripción técnica')
                            ->rows(3)
                            ->maxLength(2000)
                            ->placeholder('Ej: 4MP, lente 2.8mm, IR 30m, IP67, PoE, slot microSD')
                            ->helperText('Detalles que ayuden a identificar el producto: especificaciones técnicas, características, lo que incluye.'),
                    ]),

                // ── 3. Condición + Precios ───────────────────────────
                Section::make('Precio')
                    ->aside()
                    ->description(fn ($get) => static::priceSectionDescription($get))
                    ->schema([
                        Grid::make(3)->schema([
                            // Condición: aplica a TODO producto físico (enum o
                            // custom no-servicio). Un servicio no tiene
                            // condición "nuevo/usado", es exento por su
                            // naturaleza profesional.
                            Select::make('condition')
                                ->label('Condición')
                                ->options(ProductCondition::class)
                                ->required()
                                ->default(ProductCondition::New)
                                ->visible(fn ($get) => ! static::isService($get))
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set) {
                                    $isUsed = $state === ProductCondition::Used->value
                                        || $state === ProductCondition::Used;
                                    $set('tax_type', $isUsed
                                        ? TaxType::Exento->value
                                        : TaxType::Gravado15->value);
                                }),

                            // Tipo fiscal explícito SOLO para servicios. El
                            // usuario elige (default Exento — caso típico de
                            // honorarios profesionales).
                            Select::make('tax_type')
                                ->label('Tipo fiscal')
                                ->options(TaxType::class)
                                ->default(TaxType::Exento)
                                ->required()
                                ->visible(fn ($get) => static::isService($get))
                                // CRÍTICO: dehidratar SOLO cuando es servicio.
                                // Sin esta regla, este Select dehidrataba SIEMPRE (default
                                // de Filament) y pisaba al Hidden::make('tax_type') de
                                // productos físicos enviando 'exento' en $data. Eso hacía
                                // que CreateProduct::convertPricesToBase NO convirtiera el
                                // sale_price (porque la comparación contra 'gravado_15'
                                // fallaba) y se guardaba CON ISV. El observer
                                // enforceTaxType del modelo después corregía tax_type a
                                // 'gravado_15' pero el sale_price ya quedaba mal.
                                ->dehydrated(fn ($get) => static::isService($get))
                                ->helperText('Servicios profesionales (Honorarios) son normalmente Exento.')
                                ->live(),

                            TextInput::make('cost_price')
                                ->label('Costo (neto)')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->step(0.01)
                                ->prefix('L')
                                ->default(fn ($get) => static::isService($get) ? 0 : null)
                                ->placeholder('0.00')
                                ->helperText(fn ($get) => static::isService($get)
                                    ? 'Variable. Ajustar al facturar.'
                                    : 'Costo neto del producto en libros (sin ISV). El crédito fiscal por compras se registra aparte en Compras.')
                                ->live(onBlur: true),
                            TextInput::make('sale_price')
                                ->label(fn ($get) => static::isGravado($get) ? 'Precio de venta (con ISV)' : 'Precio de venta')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->step(0.01)
                                ->prefix('L')
                                ->default(fn ($get) => static::isService($get) ? 0 : null)
                                ->placeholder('0.00')
                                ->helperText(fn ($get) => static::isService($get)
                                    ? 'Variable. Ajustar al facturar.'
                                    : (static::isGravado($get)
                                        ? 'Precio público — incluye 15% de ISV. Se descompondrá automáticamente al facturar.'
                                        : 'Precio público (exento de ISV).'))
                                ->live(onBlur: true),
                        ]),
                        Placeholder::make('price_summary')
                            ->label('')
                            ->content(fn ($get) => static::buildPriceSummary($get))
                            ->visible(fn ($get) =>
                                (float) ($get('cost_price') ?? 0) > 0
                                || (float) ($get('sale_price') ?? 0) > 0),

                        // Hidden tax_type para productos físicos (lo setea el
                        // afterStateUpdated del Condition select según
                        // Nuevo=Gravado15 / Usado=Exento). Para servicios, el
                        // TaxType select arriba ya es el campo persistido.
                        Hidden::make('tax_type')
                            ->default(TaxType::Gravado15->value)
                            ->dehydrated(fn ($get) => ! static::isService($get))
                            ->visible(fn ($get) => ! static::isService($get)),
                    ]),

                // ── 4. Stock ─────────────────────────────────────────
                // Inventario aplica a productos FÍSICOS (sean enum o custom
                // no-servicio). Para servicios (is_service=true) no tiene
                // sentido — el stock se setea internamente como infinito en
                // CreateProduct::applyServiceDefaults para que el SaleInventoryProcessor
                // no se queje de "stock insuficiente" al vender un servicio.
                Section::make('Inventario')
                    ->aside()
                    ->description('Control de existencias.')
                    ->visible(fn ($get) => ! static::isService($get))
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
                        // Para tipos enum: aquí va la descripción opcional.
                        // Para tipos custom: la descripción técnica ya está
                        // en la sección "Datos del producto" arriba — la
                        // ocultamos acá para no duplicar.
                        Textarea::make('description')
                            ->label('Descripción')
                            ->rows(2)
                            ->maxLength(2000)
                            ->placeholder('Notas adicionales del producto')
                            ->visible(fn ($get) => ! static::isCustomType($get)),
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
        // Stock infinito para tipos custom: se inyecta en
        // mutateFormDataBeforeCreate / mutateFormDataBeforeSave de las
        // pages CreateProduct/EditProduct (más confiable que Hidden fields
        // dentro de Sections con visible() condicional).
    }

    private static function priceSectionDescription($get): string
    {
        if (static::isService($get)) {
            return 'Servicio — Tipo fiscal según corresponda. Precios variables, ajustables al facturar.';
        }

        return static::isGravado($get)
            ? 'Nuevo — costo neto + precio público con ISV (15%).'
            : 'Usado — exento de ISV.';
    }

    /**
     * ¿El tipo seleccionado es CUSTOM (no es uno de los 8 enum cases)?
     * Determina si se muestra la sección "Datos del producto" con subtipo,
     * descripción técnica y el toggle is_service.
     */
    private static function isCustomType($get): bool
    {
        $val = $get('product_type');
        if (! filled($val)) {
            return false;
        }
        if ($val instanceof ProductType) {
            return false; // es enum
        }
        return ProductType::tryFrom(mb_strtolower((string) $val)) === null;
    }

    /**
     * ¿Este producto es un SERVICIO (sin inventario)?
     *
     * Solo puede serlo si es tipo CUSTOM y el usuario marcó el toggle.
     * Los tipos enum (Laptop, Desktop, etc.) son siempre productos físicos —
     * el toggle is_service no aparece para ellos.
     *
     * Determina:
     *   - Si se oculta la sección "Inventario" (servicios no llevan stock).
     *   - Si se oculta el campo "Condición" (servicios no son nuevo/usado).
     *   - Si se muestra el campo "Tipo fiscal" explícito.
     *   - Si los defaults de cost/sale_price son 0.
     *   - Comportamiento del POS al vender este producto.
     */
    private static function isService($get): bool
    {
        // Solo tipos custom pueden ser servicio.
        if (! static::isCustomType($get)) {
            return false;
        }

        // El toggle is_service decide. Si no está seteado todavía (form
        // recién renderizado), default false.
        return (bool) $get('is_service');
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
        // Case-insensitive: los enum cases están en minúsculas ('laptop'),
        // pero un tipo custom guardado en spec_options está en MAYÚSCULAS
        // ('EQUIPO DE SEGURIDAD'). tryFrom devuelve null para custom, y el
        // form lo trata como "sin specs específicos".
        return ProductType::tryFrom(mb_strtolower((string) ($val ?? '')));
    }

    /**
     * Combina los 8 tipos del enum (con labels bonitos) + los tipos custom
     * que el cliente agregó al vuelo (vienen de spec_options con field_key
     * 'product_type'). Retorna [value => label] para Filament Select.
     *
     * Los enum cases se persisten con su `value` en minúsculas ('laptop')
     * para mantener compatibilidad con el código existente que compara
     * `$selected === $type->value`. Los custom se persisten en MAYÚSCULAS
     * ('EQUIPO DE SEGURIDAD') siguiendo el patrón de spec_options.
     *
     * Si un valor custom coincide con un enum case (case-insensitive), se
     * filtra para no duplicar — el enum tiene preferencia.
     *
     * @return array<string, string>
     */
    private static function buildProductTypeOptions(): array
    {
        // 1) Tipos del enum con sus labels oficiales.
        $enumOptions = [];
        foreach (ProductType::cases() as $t) {
            $enumOptions[$t->value] = $t->getLabel();
        }

        // 2) Custom values de spec_options.
        $customRaw = SpecOption::searchOptions('product_type');

        // Filtrar duplicados: si un custom value es solo un enum en MAYÚSCULAS,
        // descartarlo porque el enum ya está arriba con su label bonito.
        $enumValuesUpper = array_map('mb_strtoupper', array_keys($enumOptions));
        $customFiltered = [];
        foreach ($customRaw as $value => $label) {
            if (in_array(mb_strtoupper($value), $enumValuesUpper, true)) {
                continue;
            }
            $customFiltered[$value] = $label;
        }

        return $enumOptions + $customFiltered;
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
            // Tipo personalizado o vacío: descripción genérica.
            $rawType = $get('product_type');
            return filled($rawType)
                ? mb_strtoupper((string) $rawType) . ' — completá los datos del producto.'
                : 'Detalles';
        }

        return match ($type) {
            ProductType::Accessory, ProductType::Component =>
                $type->getLabel() . ' — solo el tipo es requerido, marca y modelo son opcionales.',
            ProductType::Printer =>
                $type->getLabel() . ' — marca opcional para genéricos.',
            default => $type->getLabel(),
        };
    }

    /**
     * ¿Este producto se va a guardar como Gravado15 (con ISV)?
     *
     * Espeja la lógica canónica de `Product::enforceTaxType` para que las
     * etiquetas y helpers del form ("Precio de venta (con ISV)" vs
     * "Precio de venta") sean coherentes con cómo se va a almacenar.
     *
     *   - Servicio (is_service=true): respeta el Select tax_type que el
     *     usuario eligió explícitamente (Honorarios típicamente Exento).
     *   - Físico (enum o custom no-servicio): deriva de condition.
     *     Nuevo = Gravado15, Usado = Exento.
     */
    private static function isGravado($get): bool
    {
        if (static::isService($get)) {
            $taxType = $get('tax_type');
            return $taxType === TaxType::Gravado15->value
                || $taxType === TaxType::Gravado15;
        }

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
        // Convención de los campos del form:
        //   - cost_price: costo NETO (no incluye ISV) — se compara directo.
        //   - sale_price: precio CON ISV (lo que cobramos al cliente).
        $cost = (float) ($get('cost_price') ?? 0);
        $sale = (float) ($get('sale_price') ?? 0);
        $gravado = static::isGravado($get);
        $multiplier = (float) config('tax.multiplier', 1.15);
        $parts = [];

        if ($gravado && $sale > 0) {
            // Para gravado, descomponemos el sale_price en base + ISV.
            $saleBase = round($sale / $multiplier, 2);
            $saleIsv = round($sale - $saleBase, 2);
            $parts[] = "<span class='text-gray-500 dark:text-gray-400'>Venta: L "
                . number_format($saleBase, 2) . " + ISV L " . number_format($saleIsv, 2) . "</span>";
        }

        if ($cost > 0 && $sale > 0) {
            // Ganancia = base de venta − costo neto. El cost_price del form
            // YA es neto (no se divide entre 1.15), así que se usa directo.
            $saleBase = $gravado ? round($sale / $multiplier, 2) : $sale;
            $profit = round($saleBase - $cost, 2);
            $margin = $cost > 0 ? round(($profit / $cost) * 100, 2) : 0;
            $color = $margin >= 20 ? 'text-green-500' : ($margin >= 10 ? 'text-yellow-500' : 'text-red-500');
            $parts[] = "<span class='{$color} font-semibold'>Ganancia: L "
                . number_format($profit, 2) . " ({$margin}%)</span>";
        }

        return new HtmlString(
            empty($parts) ? '' : "<div class='text-sm'>" . implode(' &nbsp;·&nbsp; ', $parts) . "</div>"
        );
    }
}

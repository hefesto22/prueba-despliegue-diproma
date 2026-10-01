<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductCondition;
use App\Enums\ProductType;
use App\Enums\TaxType;
use App\Models\Product;
use App\Models\SpecOption;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Formulario de Productos (crear y editar).
 *
 * Diseño guiado (aprobado 2026-09-30), el mismo patrón que Compras: pasos
 * numerados en una sola página y un resumen fijo a la derecha.
 *
 *   1. ¿Qué estás registrando?  → botones con ícono para los 8 tipos del
 *      enum + "Otro tipo…", que abre el buscador de tipos personalizados
 *      (Honorarios, Equipo de seguridad…) con la opción de escribir uno nuevo,
 *      y la pregunta "¿Es un producto o un servicio?".
 *   2. Datos del producto       → marca, modelo y specs del tipo; en tipos
 *      personalizados: subtipo y descripción técnica.
 *   3. Precio                   → condición, costo y precio de venta.
 *   4. Inventario               → stock y alerta (no aplica a servicios).
 *   Opcional (colapsado)        → descripción, seriales, imagen, activo.
 *
 * El resumen muestra el nombre y SKU que se van a generar, el desglose del
 * precio con ISV y la ganancia, para revisarlos antes de guardar.
 *
 * Sin pestañas a propósito: es una captura que se repite muchas veces al día
 * y un campo obligatorio en otra pestaña dejaría el error escondido.
 */
class ProductForm
{
    /**
     * Valor del botón "Otro tipo…": no es un tipo, abre el buscador de tipos
     * personalizados. Nunca se guarda (type_choice no se deshidrata).
     */
    private const OTHER_TYPE = 'otro';

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
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Group::make([
                    self::typeSection(),
                    self::detailsSection(),
                    self::priceSection(),
                    self::inventorySection(),
                    self::extrasSection(),
                ])->columnSpan(['lg' => 2]),
                Group::make([
                    self::summarySection(),
                ])
                    ->columnSpan(['lg' => 1])
                    // Estilo en línea y no clases de Tailwind: el tema compilado
                    // (public/build) no incluye `sticky` (igual que en Compras).
                    ->extraAttributes(['style' => 'position: sticky; top: 5rem; align-self: start;']),
            ]);
        // Stock infinito para servicios: se inyecta en
        // mutateFormDataBeforeCreate / mutateFormDataBeforeSave de las
        // pages CreateProduct/EditProduct (más confiable que Hidden fields
        // dentro de Sections con visible() condicional).
    }

    // ─── 1. ¿Qué estás registrando? ─────────────────────────────────────────

    /**
     * Combina los 8 tipos del enum (botones con ícono, specs propios) con los
     * tipos personalizados que el cliente haya agregado (Equipo de seguridad,
     * Honorarios…).
     *
     * `type_choice` es solo la botonera; el valor que se guarda es
     * `product_type`. Con un tipo del enum, el botón escribe product_type y el
     * buscador queda oculto (pero se sigue enviando). Con "Otro tipo…" aparece
     * el buscador: si el tipo no existe, se escribe y queda registrado en
     * spec_options para la próxima vez — mismo patrón que RAM o procesador.
     */
    private static function typeSection(): Section
    {
        return Section::make('1. ¿Qué estás registrando?')
            ->description('Los campos de abajo se ajustan al tipo elegido.')
            ->compact()
            ->schema([
                ToggleButtons::make('type_choice')
                    ->hiddenLabel()
                    ->options(self::typeChoiceOptions())
                    ->icons(self::typeChoiceIcons())
                    ->inline()
                    ->default(ProductType::Laptop->value)
                    ->required()
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(function (ToggleButtons $component, ?Product $record): void {
                        // Al editar, el botón marcado sale del tipo guardado.
                        if ($record !== null) {
                            $component->state(self::typeChoiceFor($record->product_type));
                        }
                    })
                    ->afterStateUpdated(function (?string $state, callable $set): void {
                        $set('product_type', $state === self::OTHER_TYPE ? null : $state);
                        self::clearSpecFields($set);
                    }),

                Select::make('product_type')
                    ->label('Tipo')
                    ->placeholder('Escriba o elija el tipo')
                    ->helperText('Si no está en la lista, escríbalo: se guarda para la próxima vez.')
                    ->required()
                    ->default(ProductType::Laptop->value)
                    ->searchable()
                    // Sin búsqueda se listan solo los personalizados: los del
                    // enum ya están en los botones de arriba.
                    ->options(fn (): array => self::customProductTypeOptions())
                    ->getSearchResultsUsing(fn (string $search): array => self::searchProductTypes($search))
                    ->getOptionLabelUsing(function (?string $value): ?string {
                        if (! filled($value)) {
                            return null;
                        }

                        // Enum: su label oficial. Custom: tal cual (MAYÚSCULAS).
                        return ProductType::tryFrom(mb_strtolower($value))?->getLabel() ?? $value;
                    })
                    ->visible(fn (callable $get): bool => $get('type_choice') === self::OTHER_TYPE)
                    // Oculto cuando el tipo se eligió con un botón, pero es el
                    // campo que se guarda: tiene que viajar igual.
                    ->dehydratedWhenHidden()
                    ->live()
                    ->afterStateUpdated(function (?string $state, callable $set): void {
                        self::clearSpecFields($set);

                        // Si buscó un tipo que ya tiene botón (ej. "lap" → Laptop),
                        // se marca ese botón para que aparezcan sus specs.
                        $enum = ProductType::tryFrom(mb_strtolower((string) $state));
                        if ($enum !== null) {
                            $set('type_choice', $enum->value);
                        }
                    }),

                self::serviceQuestion(),
            ]);
    }

    /**
     * ¿Producto o servicio? Solo para tipos personalizados: los del enum son
     * siempre productos físicos. Va pegada al tipo porque decide el resto del
     * formulario: si se muestran Condición e Inventario, si el POS deja editar
     * el precio y si se descuenta stock al vender.
     *
     * Producto va primero y es el default: ante la duda, no se oculta el
     * inventario.
     */
    private static function serviceQuestion(): ToggleButtons
    {
        return ToggleButtons::make('is_service')
            ->label('¿Es un producto o un servicio?')
            // boolean() aporta el cast a bool; las opciones se redefinen para
            // poner Producto primero y con textos claros.
            ->boolean()
            ->options([
                0 => 'Producto (lleva inventario)',
                1 => 'Servicio u honorario (sin inventario)',
            ])
            ->icons([
                0 => 'heroicon-o-cube',
                1 => 'heroicon-o-wrench-screwdriver',
            ])
            ->colors([
                0 => 'primary',
                1 => 'primary',
            ])
            ->helperText(fn (callable $get): string => self::isService($get)
                ? 'Honorarios, instalación, mantenimiento, asesoría: no lleva stock y el precio se ajusta al facturar.'
                : 'Cámaras, biométricos, equipos: lleva stock y se descuenta al vender.')
            ->inline()
            ->default(false)
            ->visible(fn (callable $get): bool => self::isCustomType($get))
            ->live();
    }

    /**
     * @return array<string, string>
     */
    private static function typeChoiceOptions(): array
    {
        $options = [];
        foreach (ProductType::cases() as $type) {
            $options[$type->value] = $type->getLabel();
        }

        return $options + [self::OTHER_TYPE => 'Otro tipo…'];
    }

    /**
     * @return array<string, string>
     */
    private static function typeChoiceIcons(): array
    {
        return [
            ProductType::Laptop->value => 'heroicon-o-computer-desktop',
            ProductType::Desktop->value => 'heroicon-o-server-stack',
            ProductType::Tablet->value => 'heroicon-o-device-tablet',
            ProductType::Console->value => 'heroicon-o-puzzle-piece',
            ProductType::Monitor->value => 'heroicon-o-tv',
            ProductType::Printer->value => 'heroicon-o-printer',
            ProductType::Component->value => 'heroicon-o-cpu-chip',
            ProductType::Accessory->value => 'heroicon-o-squares-plus',
            self::OTHER_TYPE => 'heroicon-o-ellipsis-horizontal-circle',
        ];
    }

    /**
     * Botón que corresponde a un product_type guardado: el del enum o
     * "Otro tipo…" si es personalizado.
     */
    private static function typeChoiceFor(mixed $productType): string
    {
        if ($productType instanceof ProductType) {
            return $productType->value;
        }

        return ProductType::tryFrom(mb_strtolower((string) $productType))?->value ?? self::OTHER_TYPE;
    }

    /**
     * Al cambiar de tipo se limpian todos los specs: cada tipo tiene los suyos
     * y un tipo personalizado no tiene ninguno.
     */
    private static function clearSpecFields(callable $set): void
    {
        foreach (ProductType::cases() as $type) {
            foreach ($type->specFields() as $field) {
                $set(static::specFieldName($type, $field['key']), null);
            }
        }
    }

    /**
     * Tipos personalizados de spec_options. Si alguno coincide con un tipo
     * del enum (case-insensitive) se descarta: el enum tiene preferencia.
     *
     * @return array<string, string>
     */
    private static function customProductTypeOptions(): array
    {
        $enumValuesUpper = array_map(
            fn (ProductType $type): string => mb_strtoupper($type->value),
            ProductType::cases(),
        );

        return array_filter(
            SpecOption::searchOptions('product_type'),
            fn (string $value): bool => ! in_array(mb_strtoupper($value), $enumValuesUpper, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Búsqueda sobre enum + personalizados. Si lo escrito no existe se ofrece
     * "(PERSONALIZADO)", que se guarda en MAYÚSCULAS al crear el producto.
     *
     * @return array<string, string>
     */
    private static function searchProductTypes(string $search): array
    {
        $base = array_map(fn (ProductType $type): string => $type->getLabel(), self::enumTypeOptions())
            + self::customProductTypeOptions();
        $needle = mb_strtolower(trim($search));

        if ($needle === '') {
            return $base;
        }

        $filtered = array_filter(
            $base,
            fn (string $label): bool => str_contains(mb_strtolower($label), $needle),
        );

        $upper = mb_strtoupper(trim($search));
        $existsAsEnum = ProductType::tryFrom($needle) !== null;
        $existsAsCustom = isset($base[$upper]);

        if (! $existsAsEnum && ! $existsAsCustom) {
            $filtered = [$upper => "{$upper} (PERSONALIZADO)"] + $filtered;
        }

        return $filtered;
    }

    /**
     * @return array<string, ProductType>
     */
    private static function enumTypeOptions(): array
    {
        $options = [];
        foreach (ProductType::cases() as $type) {
            $options[$type->value] = $type;
        }

        return $options;
    }

    // ─── 2. Datos del producto ──────────────────────────────────────────────

    private static function detailsSection(): Section
    {
        return Section::make('2. Datos del producto')
            ->description(fn (callable $get): ?string => self::detailsSectionDescription($get))
            ->compact()
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('brand')
                        ->label('Marca')
                        ->maxLength(100)
                        ->placeholder(fn (callable $get): string => self::isSimpleType($get)
                            ? 'Opcional — vacío si es genérico'
                            : 'HP, DELL, SONY...')
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? mb_strtoupper($state) : $state)
                        ->afterStateUpdated(fn (callable $set, $state) => $set('brand', filled($state) ? mb_strtoupper($state) : $state))
                        ->live(onBlur: true),
                    TextInput::make('model')
                        ->label('Modelo')
                        ->maxLength(100)
                        ->placeholder(fn (callable $get): string => self::isSimpleType($get)
                            ? 'Opcional'
                            : 'PROBOOK 450 G10')
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? mb_strtoupper($state) : $state)
                        ->afterStateUpdated(fn (callable $set, $state) => $set('model', filled($state) ? mb_strtoupper($state) : $state))
                        ->live(onBlur: true),
                ]),

                // Campos dinámicos por tipo (state paths ÚNICOS por tipo)
                ...static::buildDynamicSpecFields(),

                // Tipos personalizados: no tienen schema de specs; un
                // subclasificador y una descripción libre los identifican.
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
                    ->helperText('Ej: Cámara IP, DVR, Biométrico, Instalación. Si no existe, escríbalo y se guarda.')
                    ->visible(fn (callable $get): bool => self::isCustomType($get))
                    ->live(),

                Textarea::make('description')
                    ->label('Descripción técnica')
                    ->rows(3)
                    ->maxLength(2000)
                    ->placeholder('Ej: 4MP, lente 2.8mm, IR 30m, IP67, PoE, slot microSD')
                    ->helperText('Especificaciones, características o lo que incluye.')
                    ->visible(fn (callable $get): bool => self::isCustomType($get)),
                self::printDescriptionCheckbox()
                    ->visible(fn (callable $get): bool => self::isCustomType($get)),

                Hidden::make('name')->dehydrated(),
            ]);
    }

    private static function detailsSectionDescription(callable $get): ?string
    {
        if (self::isCustomType($get)) {
            return 'Subtipo y descripción ayudan a identificarlo al vender.';
        }

        return match (self::getProductType($get)) {
            ProductType::Accessory, ProductType::Component => 'Marca y modelo son opcionales.',
            ProductType::Printer => 'Marca opcional para genéricos.',
            default => null,
        };
    }

    // ─── 3. Precio ──────────────────────────────────────────────────────────

    private static function priceSection(): Section
    {
        return Section::make('3. Precio')
            ->description(fn (callable $get): string => self::priceSectionDescription($get))
            ->compact()
            ->schema([
                Grid::make(3)->schema([
                    // Condición: aplica a TODO producto físico (enum o custom
                    // no-servicio). Un servicio no es nuevo/usado.
                    ToggleButtons::make('condition')
                        ->label('Condición')
                        ->options(ProductCondition::class)
                        ->icons([
                            ProductCondition::New->value => 'heroicon-o-sparkles',
                            ProductCondition::Used->value => 'heroicon-o-arrow-path',
                        ])
                        ->inline()
                        ->required()
                        ->default(ProductCondition::New)
                        ->visible(fn (callable $get): bool => ! self::isService($get))
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
                        ->helperText('Los honorarios normalmente son exentos.')
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
                            : 'Sin ISV. El crédito fiscal va aparte, en Compras.')
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
                                ? 'Lo que paga el cliente, con el 15% incluido.'
                                : 'Lo que paga el cliente (exento de ISV).'))
                        ->live(onBlur: true),
                ]),

                // Hidden tax_type para productos físicos (lo setea el
                // afterStateUpdated de Condición según Nuevo=Gravado15 /
                // Usado=Exento). Para servicios, el Select de arriba ya es el
                // campo persistido.
                Hidden::make('tax_type')
                    ->default(TaxType::Gravado15->value)
                    ->dehydrated(fn ($get) => ! static::isService($get))
                    ->visible(fn ($get) => ! static::isService($get)),
            ]);
    }

    private static function priceSectionDescription(callable $get): string
    {
        if (static::isService($get)) {
            return 'Servicio: precio variable, se ajusta al facturar.';
        }

        return static::isGravado($get)
            ? 'Nuevo: lleva 15% de ISV.'
            : 'Usado: exento de ISV.';
    }

    // ─── 4. Inventario ──────────────────────────────────────────────────────

    /**
     * Solo productos FÍSICOS (enum o custom no-servicio). A un servicio se le
     * pone stock infinito en CreateProduct::applyServiceDefaults para que el
     * POS no se queje de "stock insuficiente".
     *
     * Los dos campos empiezan vacíos y vacío se guarda como 0: no traen un 0
     * escrito que haya que borrar antes de teclear.
     */
    private static function inventorySection(): Section
    {
        return Section::make('4. Inventario')
            ->compact()
            ->visible(fn (callable $get): bool => ! self::isService($get))
            ->schema([
                Grid::make(2)->schema([
                    self::quantityInput('stock')
                        ->label('Cantidad en stock')
                        // El parámetro se llama $operation a propósito:
                        // Filament v4 resuelve los argumentos de closure
                        // por NOMBRE, no por posición.
                        ->helperText(fn (string $operation): string => $operation === 'create'
                            ? 'Entra al Kardex como carga inicial. Vacío = 0.'
                            : 'Si cambia este número se registra un ajuste (+/−) en el Kardex.'),
                    self::quantityInput('min_stock')
                        ->label('Alerta de stock mínimo')
                        ->helperText('Avisa cuando el stock baje de aquí. Vacío = sin alerta.'),
                ]),
            ]);
    }

    private static function quantityInput(string $field): TextInput
    {
        return TextInput::make($field)
            ->integer()
            ->minValue(0)
            ->placeholder('0')
            ->live(onBlur: true)
            ->dehydrateStateUsing(fn ($state): int => blank($state) ? 0 : (int) $state);
    }

    // ─── Opcional ───────────────────────────────────────────────────────────

    private static function extrasSection(): Section
    {
        return Section::make('Opcional')
            ->description('Descripción, números de serie, imagen.')
            ->compact()
            ->collapsible()
            ->collapsed()
            ->schema([
                // En tipos personalizados la descripción técnica ya está en
                // el paso 2 — se oculta aquí para no duplicar el campo.
                Textarea::make('description')
                    ->label('Descripción')
                    ->rows(2)
                    ->maxLength(2000)
                    ->placeholder('Notas adicionales del producto')
                    ->visible(fn (callable $get): bool => ! self::isCustomType($get)),
                self::printDescriptionCheckbox()
                    ->visible(fn (callable $get): bool => ! self::isCustomType($get)),
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
            ]);
    }

    /**
     * Casilla junto a la descripción (va en el paso 2 para tipos
     * personalizados y en "Opcional" para los demás; cada caller fija su
     * visibilidad igual que la de su Textarea).
     *
     * Apagada por defecto. Al vender se congela en sale_items.detail
     * (SaleItem::snapshotOf): cambiarla después no altera facturas emitidas.
     */
    private static function printDescriptionCheckbox(): Checkbox
    {
        return Checkbox::make('print_description')
            ->label('Imprimir esta descripción en la factura')
            ->helperText('Sale en letra pequeña debajo del nombre del producto. Aplica a las ventas que se hagan desde ahora.')
            ->default(false);
    }

    // ─── Resumen (columna derecha) ──────────────────────────────────────────

    private static function summarySection(): Section
    {
        return Section::make('Resumen')
            ->icon('heroicon-o-cube')
            ->compact()
            ->schema([
                Placeholder::make('product_summary')
                    ->hiddenLabel()
                    ->content(fn (callable $get, string $operation, ?Product $record): HtmlString => self::renderSummary($get, $operation, $record)),
            ]);
    }

    /**
     * Nombre y SKU que se van a generar (el modelo los arma al guardar con la
     * misma regla), desglose del precio y ganancia, y qué pasa con el stock.
     */
    private static function renderSummary(callable $get, string $operation, ?Product $record): HtmlString
    {
        $isService = self::isService($get);
        $isGravado = self::isGravado($get);
        $cost = self::moneyFromState($get('cost_price'));
        $sale = self::moneyFromState($get('sale_price'));
        $saleBase = $isGravado && $sale !== null ? round(Product::priceWithoutIsv($sale), 2) : $sale;

        return new HtmlString(view('filament.forms.product-summary', [
            'name' => self::previewName($get),
            'sku' => $record?->sku ?? self::previewSku($get),
            'skuIsPreview' => $record?->sku === null,
            'typeLabel' => self::typeLabel($get),
            'conditionLabel' => $isService ? 'Servicio' : self::conditionFromState($get('condition'))?->getLabel(),
            'isService' => $isService,
            'isGravado' => $isGravado,
            'cost' => $cost,
            'sale' => $sale,
            'saleBase' => $saleBase,
            'isv' => $isGravado && $sale !== null ? round($sale - $saleBase, 2) : null,
            'profit' => $cost !== null && $cost > 0 && $saleBase !== null ? round($saleBase - $cost, 2) : null,
            'stock' => blank($get('stock')) ? 0 : (int) $get('stock'),
            'isCreating' => $operation === 'create',
        ])->render());
    }

    private static function moneyFromState(mixed $state): ?float
    {
        return is_numeric($state) && (float) $state > 0 ? round((float) $state, 2) : null;
    }

    private static function conditionFromState(mixed $state): ?ProductCondition
    {
        return $state instanceof ProductCondition ? $state : ProductCondition::tryFrom((string) $state);
    }

    private static function typeLabel(callable $get): ?string
    {
        $rawType = $get('product_type');

        if (! filled($rawType)) {
            return null;
        }

        return self::getProductType($get)?->getLabel() ?? mb_strtoupper((string) $rawType);
    }

    /**
     * Mismo armado que Product::autoGenerateName, sobre el estado del form.
     */
    private static function previewName(callable $get): ?string
    {
        $rawType = $get('product_type');

        if (! filled($rawType)) {
            return null;
        }

        $brand = (string) ($get('brand') ?? '');
        $model = (string) ($get('model') ?? '');
        $type = self::getProductType($get);

        if ($type !== null) {
            return $type->generateName($brand, $model, self::collectSpecs($get, $type));
        }

        // Tipo personalizado: tipo + marca + modelo + subtipo.
        $parts = array_filter([$rawType, $brand, $model], fn ($part): bool => filled($part));
        $name = mb_strtoupper(implode(' ', $parts));

        $subtype = $get('specs.subtype');
        if (filled($subtype)) {
            $name .= ' - '.mb_strtoupper((string) $subtype);
        }

        return $name;
    }

    /**
     * Prefijo del SKU que asignará Product::autoGenerateSku; el correlativo
     * se conoce hasta guardar.
     */
    private static function previewSku(callable $get): ?string
    {
        $rawType = $get('product_type');

        if (! filled($rawType)) {
            return null;
        }

        $typePrefix = self::getProductType($get)?->skuPrefix();
        if ($typePrefix === null) {
            $clean = strtoupper(preg_replace('/[^a-zA-Z]/', '', (string) $rawType) ?: '');
            $typePrefix = $clean !== '' ? substr($clean, 0, 3) : 'GEN';
        }

        $brand = (string) ($get('brand') ?? '');
        $brandPrefix = filled($brand)
            ? strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $brand) ?: '', 0, 3) ?: 'GEN')
            : 'GEN';

        return "{$typePrefix}-{$brandPrefix}-XXXXX";
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * ¿El tipo seleccionado es CUSTOM (no es uno de los 8 enum cases)?
     * Determina si se muestran "¿producto o servicio?", subtipo y descripción técnica.
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
}

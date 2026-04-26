<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Enums\TaxType;
use App\Models\Establishment;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Purchases\PurchaseTotalsCalculator;
use App\Services\Purchases\SupplierDocumentPrefill;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/**
 * Helpers de detección reutilizables — evitan repetir el chequeo string vs enum
 * en múltiples callbacks live(). Filament puede entregar el state como string
 * (durante edición del form) o como enum value, así que normalizamos a string.
 */

class PurchaseForm
{
    /**
     * ¿El state actual del form corresponde a Recibo Interno?
     *
     * Filament entrega el state de este campo de dos formas distintas según el
     * callback desde donde se invoque:
     *   - string (el `->value` del enum) durante afterStateUpdated y primera
     *     hidratación del form
     *   - instancia de SupplierDocumentType cuando el cast del modelo ya lo
     *     hidrató (callbacks de visible/required/dehydrated después de edit)
     *
     * Normalizamos ambos casos para que el llamador no tenga que importar el
     * enum ni decidir qué comparar.
     */
    private static function isReciboInterno(SupplierDocumentType|string|null $documentType): bool
    {
        $value = $documentType instanceof SupplierDocumentType
            ? $documentType->value
            : $documentType;

        return $value === SupplierDocumentType::ReciboInterno->value;
    }

    /**
     * Mapear una colección de Product a [id => HTML option] para el Select.
     *
     * Se extrajo a método propio para que options() y getSearchResultsUsing()
     * compartan la misma representación visual sin duplicar lógica (DRY).
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return array<int, string>
     */
    private static function buildProductOptions($products): array
    {
        return $products
            ->mapWithKeys(fn (Product $p) => [$p->id => self::renderProductOption($p)])
            ->all();
    }

    /**
     * Renderizar una opción del Select de producto con badge de condición,
     * SKU, nombre, costo y stock disponible.
     *
     * Se usa tanto en el dropdown de búsqueda como en getOptionLabelUsing
     * (cuando ya hay un producto seleccionado al editar). Delega el render
     * a una Blade view que usa componentes Filament nativos
     * (`<x-filament::badge>`) — así heredamos colores light/dark consistentes
     * con el resto del panel sin maquetar inline styles a mano.
     *
     * Blade escapa por defecto con {{ }}, así que datos del modelo
     * (sku/name/brand) están protegidos contra XSS en defensa profunda.
     */
    private static function renderProductOption(Product $product): string
    {
        return view('filament.forms.product-option', [
            'product' => $product,
        ])->render();
    }

    /**
     * Render COMPACTO del producto — se usa solo cuando el valor YA está
     * seleccionado dentro del Select (getOptionLabelUsing).
     *
     * Motivo: el render rico (product-option.blade.php) que mostramos en el
     * dropdown de búsqueda es demasiado ancho cuando Filament lo re-renderiza
     * como "valor actual" — el nombre del producto se desborda y pisa los
     * campos vecinos (Cantidad, Costo c/u). Este render minimalista (badge +
     * nombre truncado + SKU) cabe en cualquier ancho de columna.
     *
     * La info rica (condición, tax, stock, costo histórico) ya aparece en el
     * banner inferior (product-info-banner.blade.php), así que no se pierde
     * información — solo se mueve a donde tiene espacio para mostrarse bien.
     */
    private static function renderProductOptionCompact(Product $product): string
    {
        return view('filament.forms.product-option-compact', [
            'product' => $product,
        ])->render();
    }

    /**
     * Banner informativo del producto seleccionado dentro del item de compra.
     *
     * Muestra de un vistazo: condición (Nuevo/Usado), tratamiento fiscal
     * (Gravado/Exento), stock actual y costo histórico. Es información que
     * el operador necesita para decidir si la compra tiene sentido (¿ya hay
     * mucho stock? ¿el costo de hoy es muy distinto al histórico?).
     *
     * Retorna HtmlString porque Filament Placeholder::content() así lo espera
     * para no escapar el HTML del componente Blade.
     */
    private static function renderProductInfoBanner(?int $productId): HtmlString
    {
        if (! $productId) {
            return new HtmlString('');
        }

        $product = Product::find($productId);
        if (! $product) {
            return new HtmlString('');
        }

        return new HtmlString(
            view('filament.forms.product-info-banner', [
                'product' => $product,
            ])->render()
        );
    }

    /**
     * Construir el array de totales a partir del estado actual de los items.
     *
     * Usa `PurchaseTotalsCalculator::calculateLineFigures()` como fuente única
     * de aritmética fiscal — la misma lógica que persiste el backend al
     * confirmar la compra. Así el resumen muestra exactamente lo que el
     * Service va a guardar, sin duplicar reglas.
     *
     * Lee también el `document_type` del form para que el cálculo respete la
     * regla de separación de ISV: factura/NC/ND separan; Recibo Interno NO
     * separa (no hay ISV deducible en compras informales sin CAI).
     *
     * Items con quantity=0 o unit_cost=0 se ignoran en el conteo y totales.
     *
     * @return array{
     *   items_count: int,
     *   taxable: float,
     *   exempt: float,
     *   isv: float,
     *   total: float,
     *   separates_isv: bool
     * }
     */
    private static function buildSummary(callable $get): array
    {
        $items      = $get('items') ?? [];
        $multiplier = (float) config('tax.multiplier', 1.15);

        $documentTypeRaw  = $get('document_type');
        $documentTypeEnum = $documentTypeRaw instanceof SupplierDocumentType
            ? $documentTypeRaw
            : ($documentTypeRaw ? SupplierDocumentType::tryFrom((string) $documentTypeRaw) : null);

        // separatesIsv true por default cuando no hay document_type aún —
        // mantiene comportamiento legacy en formularios donde el campo
        // todavía no se hidrató (ej. primer render antes de defaults).
        $separatesIsv = $documentTypeEnum?->separatesIsv() ?? true;

        $taxable = 0.0;
        $exempt  = 0.0;
        $isv     = 0.0;
        $total   = 0.0;
        $count   = 0;

        foreach ($items as $item) {
            $qty  = (int) ($item['quantity'] ?? 0);
            $cost = (float) ($item['unit_cost'] ?? 0);

            if ($qty <= 0 || $cost <= 0) {
                continue;
            }

            $count++;

            $taxTypeRaw  = $item['tax_type'] ?? null;
            $taxTypeEnum = $taxTypeRaw instanceof TaxType
                ? $taxTypeRaw
                : ($taxTypeRaw ? TaxType::tryFrom((string) $taxTypeRaw) : null);

            [$base, $lineIsv, $lineTotal] = PurchaseTotalsCalculator::calculateLineFigures(
                unitCost: $cost,
                quantity: $qty,
                taxType: $taxTypeEnum,
                multiplier: $multiplier,
                documentType: $documentTypeEnum,
            );

            // En documentos que NO separan ISV (RI), todo va a "exento" en el
            // resumen porque el operador no debería ver "Subtotal gravado" con
            // base sin ISV cuando no se está separando nada. Mantiene la UI
            // honesta con la realidad del cálculo.
            if ($separatesIsv && $taxTypeEnum === TaxType::Gravado15) {
                $taxable += $base;
            } else {
                $exempt += $base;
            }

            $isv   += $lineIsv;
            $total += $lineTotal;
        }

        return [
            'items_count'   => $count,
            'taxable'       => round($taxable, 2),
            'exempt'        => round($exempt, 2),
            'isv'           => round($isv, 2),
            'total'         => round($total, 2),
            'separates_isv' => $separatesIsv,
        ];
    }

    /**
     * Renderizar el panel de resumen como HtmlString para Placeholder::content().
     */
    private static function renderSummary(callable $get): HtmlString
    {
        return new HtmlString(
            view('filament.forms.purchase-summary', [
                'summary' => self::buildSummary($get),
            ])->render()
        );
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([

                // ── 1. Información de la compra ─────────────────────
                Section::make('Información de la compra')
                    ->icon('heroicon-o-building-storefront')
                    ->description('Proveedor, sucursal, fecha y condiciones de pago.')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('supplier_id')
                                ->label('Proveedor')
                                ->relationship(
                                    name: 'supplier',
                                    titleAttribute: 'name',
                                    // Excluye genéricos del listado operativo: el genérico de RI
                                    // se asigna automáticamente como fallback en
                                    // CreatePurchase::resolveReciboInternoFields cuando el
                                    // operador no elige proveedor real. No debe aparecer
                                    // entre los proveedores "reales" del dropdown.
                                    modifyQueryUsing: fn ($query) => $query->active()->operational(),
                                )
                                ->searchable()
                                ->preload()
                                // Obligatorio en factura, opcional en RI:
                                //   - Factura: SAR exige proveedor identificado con RTN.
                                //   - RI: control interno; el operador puede elegir el proveedor
                                //     real (mejor trazabilidad) o dejarlo vacío (cae al genérico
                                //     "Varios / Sin identificar" en el handler de CreatePurchase).
                                ->required(fn (callable $get) => ! self::isReciboInterno($get('document_type')))
                                ->live()
                                // Siempre dehydrated: el operador puede elegir proveedor incluso
                                // en RI (caso "le compro a Comercial El Norte sin factura — quiero
                                // saber a quién le compré"). El handler resolveReciboInternoFields
                                // respeta lo elegido y solo cae al genérico si viene vacío.
                                ->dehydrated()
                                ->helperText(fn (callable $get) => self::isReciboInterno($get('document_type'))
                                    ? 'Opcional. Si lo deja vacío se asignará "Varios / Sin identificar".'
                                    : null)
                                ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                    if (! $state) {
                                        return;
                                    }

                                    // ── Crédito a proveedores: pendiente de implementación ──
                                    // El módulo de Cuentas por Pagar (registro de pagos parciales,
                                    // conciliación, antigüedad de saldos, notificaciones de
                                    // vencimiento) NO está implementado todavía. Hasta que se
                                    // construya ese flujo completo, todas las compras se registran
                                    // al contado para evitar data corruption (compras "pendientes"
                                    // sin forma operativa de marcarlas pagadas).
                                    //
                                    // La columna `suppliers.credit_days` se mantiene en BD como
                                    // base para cuando se implemente el módulo, pero NO se hereda
                                    // al form de compra. El campo `credit_days` de la compra queda
                                    // forzado a 0 vía Hidden::make() abajo. Eliminar este comentario
                                    // y restaurar `$set('credit_days', $supplier->credit_days)`
                                    // cuando el módulo de CxP esté completo.

                                    // Auto-fill desde la última compra confirmada del proveedor.
                                    // Solo aplica en modo creación y cuando los campos están vacíos,
                                    // para no pisar datos que el operador ya escribió manualmente.
                                    // En RI no se ejecuta porque el bloque document_type lo limpia después.
                                    if (self::isReciboInterno($get('document_type'))) {
                                        return;
                                    }

                                    $alreadyHasCai = filled($get('supplier_cai'));
                                    $alreadyHasInvoice = filled($get('supplier_invoice_number'));

                                    if ($alreadyHasCai && $alreadyHasInvoice) {
                                        return;
                                    }

                                    $prefill = app(SupplierDocumentPrefill::class)->forSupplier((int) $state);

                                    if ($prefill === null) {
                                        return;
                                    }

                                    if (! $alreadyHasCai && $prefill['cai'] !== null) {
                                        $set('supplier_cai', $prefill['cai']);
                                    }

                                    if (! $alreadyHasInvoice && $prefill['invoice_prefix'] !== null) {
                                        // Solo el prefijo XXX-XXX-XX- ; el operador escribe los 8 dígitos del correlativo.
                                        $set('supplier_invoice_number', $prefill['invoice_prefix']);
                                    }

                                    if ($prefill['source_date'] !== null) {
                                        $set('_prefill_source_date', $prefill['source_date']);
                                    }
                                }),
                            Select::make('establishment_id')
                                ->label('Sucursal')
                                ->relationship(
                                    name: 'establishment',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: fn ($query) => $query->where('is_active', true),
                                )
                                ->searchable()
                                ->preload()
                                ->required()
                                ->native(false)
                                ->default(fn () => Establishment::main()->value('id'))
                                ->helperText('Sucursal a la que entra el stock.')
                                // Solo editable mientras la compra sea borrador — después la sucursal
                                // queda atada al stock ya ingresado y cambiarla rompería el kardex.
                                ->disabled(fn (?\App\Models\Purchase $record) =>
                                    $record !== null && $record->status !== PurchaseStatus::Borrador
                                )
                                ->dehydrated(),
                            DatePicker::make('date')
                                ->label('Fecha de compra')
                                ->required()
                                ->default(now())
                                ->native(false),
                        ]),
                        // credit_days oculto y forzado a 0 — ver comentario en afterStateUpdated
                        // del Select de proveedor. El campo se persiste explícitamente para que
                        // el hook creating() del modelo Purchase haga el match con `=== 0` y marque
                        // la compra como Pagada automáticamente (regla de dominio: contado = pagada).
                        Hidden::make('credit_days')
                            ->default(0)
                            ->dehydrated(),
                        Grid::make(2)->schema([
                            Placeholder::make('payment_terms_display')
                                ->label('Condición de pago')
                                ->content('Contado'),
                            TextInput::make('purchase_number')
                                ->label('# Compra')
                                ->disabled()
                                ->dehydrated()
                                ->placeholder('Se genera automáticamente')
                                ->visible(fn (string $operation) => $operation === 'edit'),
                        ]),
                    ]),

                // ── 2. Documento fiscal del proveedor (SAR) ─────────
                Section::make('Documento fiscal del proveedor')
                    ->icon('heroicon-o-document-text')
                    ->description('Datos SAR para el Libro de Compras.')
                    ->schema([
                        // Flag interno para el hint del auto-fill. No se persiste:
                        // solo sobrevive mientras dure el form en el cliente para que
                        // helperText() del # factura y del CAI muestren la fecha origen.
                        Hidden::make('_prefill_source_date')
                            ->dehydrated(false),

                        // Banner informativo visible solo cuando se elige RI. Advierte al
                        // operador sobre las implicaciones fiscales ANTES de que complete
                        // la compra — evita que use RI por desconocimiento cuando el
                        // proveedor sí tiene CAI (uso incorrecto más común).
                        Placeholder::make('recibo_interno_info')
                            ->label('')
                            ->visible(fn (callable $get) => self::isReciboInterno($get('document_type')))
                            ->content(new HtmlString(
                                '<div class="rounded-lg bg-gray-100 dark:bg-gray-800 p-4 text-sm">'
                                .'<div class="font-semibold text-gray-900 dark:text-gray-100 mb-1">📝 Recibo Interno (sin CAI)</div>'
                                .'<div class="text-gray-700 dark:text-gray-300">'
                                .'Se registra una compra informal para control interno. '
                                .'<strong>No entra al Libro de Compras SAR</strong>, no genera crédito fiscal ni es deducible de ISR. '
                                .'Úsese solo cuando el proveedor no emite factura con CAI (mercado, venta informal, etc.).'
                                .'</div></div>'
                            )),
                        Grid::make(3)->schema([
                            Select::make('document_type')
                                ->label('Tipo de documento')
                                ->options(SupplierDocumentType::class)
                                ->default(SupplierDocumentType::Factura->value)
                                ->required()
                                ->native(false)
                                ->live()
                                // Al cambiar a RI se limpian campos fiscales que ya no aplican
                                // (CAI, # del proveedor) para que el payload quede consistente
                                // antes del submit.
                                //
                                // supplier_id NO se limpia: el operador puede haber elegido un
                                // proveedor real a propósito para trazabilidad interna ("le
                                // compré a Comercial El Norte sin factura, quiero registrarlo").
                                // Si lo limpiáramos al cambiar el tipo perderíamos la elección
                                // y forzaríamos al operador a buscarlo de nuevo. El handler
                                // CreatePurchase::resolveReciboInternoFields cae al genérico
                                // solo si el operador deja el campo vacío.
                                ->afterStateUpdated(function ($state, callable $set) {
                                    if (self::isReciboInterno($state)) {
                                        // '' (no null) para no disparar el bug "null" de Alpine mask
                                        // si el usuario vuelve a cambiar a Factura y el campo reaparece.
                                        $set('supplier_cai', '');
                                        $set('supplier_invoice_number', '');
                                        $set('credit_days', 0);
                                        $set('_prefill_source_date', null);
                                    }
                                }),
                            TextInput::make('supplier_invoice_number')
                                ->label('# documento del proveedor')
                                ->placeholder('000-001-01-00000123')
                                // Default explícito vacío (NO null) para que Alpine mask no
                                // stringifique null → "null" en el input al primer render.
                                ->default('')
                                // Máscara: los guiones aparecen solos mientras el operador tipea.
                                // Acepta paste desde PDF con o sin guiones — Alpine normaliza al formato.
                                ->mask('999-999-99-99999999')
                                ->helperText(fn (callable $get) => filled($get('_prefill_source_date'))
                                    ? "Prefijo heredado de compra del {$get('_prefill_source_date')}. Escribí solo los 8 dígitos del correlativo y verificá que coincida con la factura actual."
                                    : 'Formato SAR: establecimiento-punto-tipo-correlativo')
                                // En RI el número lo genera InternalReceiptNumberGenerator
                                // en CreatePurchase::mutateFormDataBeforeCreate. Ocultamos
                                // el campo: no hay nada útil que el usuario pueda escribir.
                                ->visible(fn (callable $get) => ! self::isReciboInterno($get('document_type')))
                                ->required(fn (callable $get) => ! self::isReciboInterno($get('document_type')))
                                ->dehydrated(fn (callable $get) => ! self::isReciboInterno($get('document_type')))
                                ->maxLength(30)
                                ->regex('/^\d{3}-\d{3}-\d{2}-\d{8}$/')
                                ->validationMessages([
                                    'regex' => 'El formato debe ser XXX-XXX-XX-XXXXXXXX (18 dígitos con guiones).',
                                ])
                                ->rules(fn (?\App\Models\Purchase $record, callable $get) => self::isReciboInterno($get('document_type'))
                                    ? []
                                    : [
                                        Rule::unique('purchases', 'supplier_invoice_number')
                                            ->where('supplier_id', $get('supplier_id'))
                                            ->where('document_type', $get('document_type'))
                                            ->ignore($record?->id),
                                    ])
                                ->columnSpan(2),
                        ]),
                        TextInput::make('supplier_cai')
                            ->label('CAI')
                            ->placeholder('XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX-XX-XX')
                            // Default explícito vacío (NO null) para que Alpine mask no
                            // stringifique null → "null" en el input al primer render.
                            ->default('')
                            // Máscara: acepta alfanuméricos (*) + guiones automáticos.
                            // Los caracteres se pasan a mayúsculas al persistir (dehydrateStateUsing).
                            ->mask('******-******-******-******-******-**-**-**')
                            ->helperText(fn (callable $get) => filled($get('_prefill_source_date'))
                                ? "CAI heredado de compra del {$get('_prefill_source_date')}. Verificá que sea el mismo que aparece en esta factura (el proveedor pudo haber renovado)."
                                : 'Código de Autorización de Impresión — obligatorio salvo NC sin CAI propio.')
                            // RI no tiene CAI por definición (el proveedor no emite documento SAR).
                            ->visible(fn (callable $get) => ! self::isReciboInterno($get('document_type')))
                            ->required(fn (callable $get) => ! self::isReciboInterno($get('document_type'))
                                && $get('document_type') !== SupplierDocumentType::NotaCredito->value)
                            ->dehydrated(fn (callable $get) => ! self::isReciboInterno($get('document_type')))
                            // 43 = 36 hexadecimales (6-6-6-6-6-2-2-2) + 7 guiones del
                            // formato oficial SAR. La columna en BD acepta hasta 50.
                            ->maxLength(43)
                            ->regex('/^[A-F0-9\-]+$/i')
                            ->validationMessages([
                                'regex' => 'El CAI solo puede contener hexadecimales (0-9, A-F) y guiones.',
                                'max' => 'El CAI no puede exceder 43 caracteres (formato SAR).',
                            ])
                            // Retornar '' (no null) evita que Alpine mask stringifique null → "null" al hidratar.
                            ->formatStateUsing(fn (?string $state) => $state ? strtoupper($state) : '')
                            ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper(trim($state)) : null),
                    ]),

                // ── 3. Items de compra ──────────────────────────────
                Section::make('Productos')
                    ->icon('heroicon-o-shopping-bag')
                    ->description('Ingrese los productos comprados con su costo (ISV incluido).')
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->hiddenLabel()
                            ->schema([
                                Grid::make(12)->schema([
                                    Select::make('product_id')
                                        ->label('Producto')
                                        ->placeholder('Buscar producto…')
                                        ->searchable()
                                        ->allowHtml()
                                        ->required()
                                        ->live()
                                        // Opciones iniciales al abrir el dropdown sin teclear:
                                        // 50 más recientes para dar contexto rápido en catálogos medianos.
                                        // La búsqueda real ocurre en getSearchResultsUsing (server-side).
                                        ->options(fn (): array => self::buildProductOptions(
                                            Product::query()
                                                ->active()
                                                ->orderByDesc('updated_at')
                                                ->limit(50)
                                                ->get()
                                        ))
                                        // Búsqueda server-side: evita cargar todo el catálogo al render
                                        // (deuda técnica del diseño anterior con ->options() masivo).
                                        // Busca en sku, name, brand y model simultáneamente.
                                        ->getSearchResultsUsing(fn (string $search): array => self::buildProductOptions(
                                            Product::query()
                                                ->active()
                                                ->where(function ($q) use ($search) {
                                                    $q->where('sku', 'like', "%{$search}%")
                                                        ->orWhere('name', 'like', "%{$search}%")
                                                        ->orWhere('brand', 'like', "%{$search}%")
                                                        ->orWhere('model', 'like', "%{$search}%");
                                                })
                                                ->orderBy('name')
                                                ->limit(50)
                                                ->get()
                                        ))
                                        // Render distinto al del dropdown: el valor YA seleccionado usa
                                        // la versión compacta (badge + nombre + SKU) para que no se desborde
                                        // y pise los campos vecinos. La info rica (condición, stock, costo
                                        // histórico) vive en el banner inferior que aparece al seleccionar.
                                        ->getOptionLabelUsing(function ($value): ?string {
                                            $product = Product::find($value);
                                            return $product ? self::renderProductOptionCompact($product) : null;
                                        })
                                        ->afterStateUpdated(function ($state, callable $set) {
                                            if ($state) {
                                                $product = Product::find($state);
                                                if ($product) {
                                                    $set('unit_cost', $product->cost_price);
                                                    $set('tax_type', $product->tax_type->value);
                                                }
                                            }
                                        })
                                        // Producto ocupa la fila completa: el Select necesita ancho
                                        // para mostrar nombre + SKU sin rebalsar sobre Cantidad/Costo.
                                        // Los tres campos numéricos de abajo (Cantidad/Costo/Total)
                                        // comparten la segunda fila con 4/4/4.
                                        ->columnSpan(12),
                                    TextInput::make('quantity')
                                        ->label('Cantidad')
                                        ->numeric()
                                        ->required()
                                        ->default(1)
                                        ->minValue(1)
                                        ->suffix('uds.')
                                        ->live(onBlur: true)
                                        ->columnSpan(4),
                                    TextInput::make('unit_cost')
                                        ->label('Costo c/u')
                                        ->numeric()
                                        ->required()
                                        ->minValue(0.01)
                                        ->step(0.01)
                                        ->prefix('L')
                                        // Sufijo dinámico según tipo de documento:
                                        //   - Factura/NC/ND: 'c/ISV' — el operador ingresa el precio CON
                                        //     ISV incluido y el calculator hace el back-out.
                                        //   - Recibo Interno: 'precio final' — no se separa ISV, el monto
                                        //     ingresado es el efectivo pagado al proveedor informal.
                                        // Path '../../document_type' sube dos niveles desde el item del
                                        // repeater (items.N.unit_cost → items.N → items → root).
                                        ->suffix(fn (callable $get) => self::isReciboInterno($get('../../document_type'))
                                            ? 'precio final'
                                            : 'c/ISV')
                                        ->live(onBlur: true)
                                        ->columnSpan(4),
                                    Placeholder::make('line_total_display')
                                        ->label('Total línea')
                                        ->content(function ($get) {
                                            $qty = (int) ($get('quantity') ?? 0);
                                            $cost = (float) ($get('unit_cost') ?? 0);
                                            $total = $qty * $cost;
                                            return new HtmlString(
                                                "<span class='text-lg font-semibold text-primary-600 dark:text-primary-400'>L " . number_format($total, 2) . "</span>"
                                            );
                                        })
                                        ->columnSpan(4),
                                    Hidden::make('tax_type')
                                        ->default(TaxType::Gravado15->value)
                                        ->dehydrated(),
                                ]),
                                // Info contextual del producto seleccionado: condición, clasificación
                                // fiscal, stock actual y costo histórico. Aparece solo cuando hay producto
                                // elegido para no generar ruido en items vacíos.
                                Placeholder::make('product_info')
                                    ->hiddenLabel()
                                    ->visible(fn (callable $get) => filled($get('product_id')))
                                    ->content(fn (callable $get): HtmlString => self::renderProductInfoBanner($get('product_id')))
                                    ->columnSpanFull(),
                                // Seriales: campo secundario colapsable — no todos los productos
                                // los usan, así que no debe saturar la fila principal del repeater.
                                Section::make('Seriales recibidos')
                                    ->icon('heroicon-o-hashtag')
                                    ->collapsible()
                                    ->collapsed()
                                    ->compact()
                                    ->schema([
                                        TagsInput::make('serial_numbers')
                                            ->hiddenLabel()
                                            ->placeholder('Escriba un serial y presione Enter'),
                                    ])
                                    ->columnSpanFull(),
                            ])
                            ->addActionLabel('Agregar producto')
                            ->reorderable(false)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->columns(1),

                        // Totales al pie del repeater — patrón clásico de factura.
                        // Alineados a la derecha con jerarquía visual hacia el Total.
                        // Se actualizan en vivo porque quantity y unit_cost son live().
                        Placeholder::make('purchase_summary')
                            ->hiddenLabel()
                            ->content(fn (callable $get): HtmlString => self::renderSummary($get))
                            ->columnSpanFull(),
                    ]),

                // ── 4. Notas ────────────────────────────────────────
                Section::make('Notas')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->description('Observaciones internas (opcional).')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Textarea::make('notes')
                            ->hiddenLabel()
                            ->rows(3)
                            ->maxLength(2000)
                            ->placeholder('Notas internas sobre esta compra'),
                    ]),
            ]);
    }
}

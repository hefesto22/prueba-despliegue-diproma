<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseKind;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Models\Establishment;
use App\Models\Purchase;
use App\Services\Purchases\Exceptions\MontosDocumentoInvalidosException;
use App\Services\Purchases\PurchaseDocumentAmounts;
use App\Services\Purchases\SupplierDocumentPrefill;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/**
 * Formulario de Compras — documento fiscal del proveedor.
 *
 * Desde la Fase 1 del rediseño Compras + Producto-lote (2026-07-25) la compra
 * no lleva líneas de producto: se transcriben los montos impresos en el
 * documento (exento / gravado / ISV) para el Libro de Compras. El inventario
 * entra por la ficha del producto.
 *
 * Se mantiene en Sections apiladas (no Tabs) a propósito: el operador está
 * transcribiendo una factura en papel y necesita ver todo el documento a la
 * vez para compararlo contra el original.
 */
class PurchaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                self::purchaseSection(),
                self::fiscalDocumentSection(),
                self::amountsSection(),
                self::notesSection(),
            ]);
    }

    // ─── 1. Información de la compra ────────────────────────────────────────

    private static function purchaseSection(): Section
    {
        return Section::make('Información de la compra')
            ->icon('heroicon-o-building-storefront')
            ->description('Qué se compró, a quién, dónde y cómo se pagó.')
            ->schema([
                // "Todo en Compras" (2026-09-28): aquí se registran también los
                // gastos operativos. El tipo decide si resta de la Utilidad Neta
                // (gasto) o entra por el costo de lo vendido (mercadería).
                Grid::make(3)->schema([
                    ToggleButtons::make('kind')
                        ->label('¿Qué se compró?')
                        ->options(PurchaseKind::class)
                        ->default(PurchaseKind::Mercaderia->value)
                        ->inline()
                        ->required()
                        ->live(),
                    Select::make('expense_category')
                        ->label('Categoría del gasto')
                        ->options(ExpenseCategory::class)
                        ->native(false)
                        ->visible(fn (callable $get) => self::isExpense($get('kind')))
                        ->required(fn (callable $get) => self::isExpense($get('kind'))),
                    TextInput::make('description')
                        ->label('Concepto')
                        ->placeholder('Ej. Gasolina moto mensajero')
                        ->maxLength(500)
                        ->visible(fn (callable $get) => self::isExpense($get('kind')))
                        ->required(fn (callable $get) => self::isExpense($get('kind'))),
                ]),
                Grid::make(3)->schema([
                    Select::make('supplier_id')
                        ->label('Proveedor')
                        ->relationship(
                            name: 'supplier',
                            titleAttribute: 'name',
                            // Excluye genéricos: el genérico de RI se asigna solo como
                            // fallback cuando el operador no elige proveedor real
                            // (ver CreatePurchase::resolveReciboInternoFields).
                            modifyQueryUsing: fn ($query) => $query->active()->operational(),
                        )
                        ->searchable()
                        ->preload()
                        // Factura: SAR exige proveedor identificado. RI: opcional
                        // (trazabilidad interna); vacío cae al genérico.
                        ->required(fn (callable $get) => ! SupplierDocumentType::isReciboInterno($get('document_type')))
                        ->live()
                        ->dehydrated()
                        ->helperText(fn (callable $get) => SupplierDocumentType::isReciboInterno($get('document_type'))
                            ? 'Opcional. Si lo deja vacío se asignará "Varios / Sin identificar".'
                            : null)
                        ->afterStateUpdated(function ($state, callable $set, callable $get) {
                            // Crédito a proveedores pausado hasta que exista Cuentas por
                            // Pagar: credit_days NO se hereda del proveedor (queda en 0
                            // vía Hidden más abajo). Restaurar
                            // `$set('credit_days', $supplier->credit_days)` cuando CxP exista.
                            if (! $state || SupplierDocumentType::isReciboInterno($get('document_type'))) {
                                return;
                            }

                            self::prefillFromLastDocument((int) $state, $set, $get);
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
                        ->helperText('Sucursal que recibió el documento.')
                        // Solo editable en Borrador: una compra confirmada ya está en
                        // el Libro de Compras filtrable por sucursal.
                        ->disabled(fn (?Purchase $record) => $record !== null && $record->status !== PurchaseStatus::Borrador)
                        ->dehydrated(),
                    DatePicker::make('date')
                        ->label('Fecha del documento')
                        ->required()
                        ->default(now())
                        ->native(false),
                ]),
                // credit_days forzado a 0 mientras Cuentas por Pagar no exista:
                // PurchaseService::confirm() marca Pagada las compras de contado.
                Hidden::make('credit_days')
                    ->default(0)
                    ->dehydrated(),
                Grid::make(2)->schema([
                    Select::make('payment_method')
                        ->label('Forma de pago')
                        ->options(PaymentMethod::class)
                        ->required()
                        ->native(false)
                        ->live()
                        ->helperText(fn (callable $get) => self::isCash($get('payment_method'))
                            ? 'Al confirmar, el dinero sale de la caja abierta de la sucursal.'
                            : 'Contado. Solo "Efectivo" descuenta de la caja.'),
                    TextInput::make('purchase_number')
                        ->label('# Compra')
                        ->disabled()
                        ->dehydrated()
                        ->placeholder('Se genera automáticamente')
                        ->visible(fn (string $operation) => $operation === 'edit'),
                ]),
            ]);
    }

    // ─── 2. Documento fiscal del proveedor ──────────────────────────────────

    private static function fiscalDocumentSection(): Section
    {
        return Section::make('Documento fiscal del proveedor')
            ->icon('heroicon-o-document-text')
            ->description('Factura: entra al Libro de Compras con su ISV. Recibo Interno: compras sin CAI (taxi, mercado, particulares).')
            ->schema([
                // Flag del auto-fill: solo vive en el cliente para que los helperText
                // del # de documento y del CAI muestren de qué compra se heredaron.
                Hidden::make('_prefill_source_date')
                    ->dehydrated(false),

                // Aviso ANTES de completar la compra: el uso incorrecto más común
                // del RI es registrar así a un proveedor que sí tiene CAI.
                Placeholder::make('recibo_interno_info')
                    ->label('')
                    ->visible(fn (callable $get) => SupplierDocumentType::isReciboInterno($get('document_type')))
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
                        ->options([
                            // Solo los dos documentos que Diproma recibe en la práctica.
                            // NC/ND de proveedor existen en el enum por estructura SAR
                            // pero no se registran en este negocio.
                            SupplierDocumentType::Factura->value => SupplierDocumentType::Factura->getLabel(),
                            SupplierDocumentType::ReciboInterno->value => SupplierDocumentType::ReciboInterno->getLabel(),
                        ])
                        ->default(SupplierDocumentType::Factura->value)
                        ->required()
                        ->native(false)
                        ->live()
                        // Al pasar a RI se limpia lo que deja de aplicar (CAI, # proveedor,
                        // gravado, ISV) para que lo validado y lo guardado coincidan. El
                        // proveedor NO se limpia: el operador pudo elegirlo a propósito.
                        ->afterStateUpdated(function ($state, callable $set) {
                            if (! SupplierDocumentType::isReciboInterno($state)) {
                                return;
                            }

                            // '' (no null): evita que la máscara de Alpine pinte "null"
                            // si el operador vuelve a Factura y el campo reaparece.
                            $set('supplier_cai', '');
                            $set('supplier_invoice_number', '');
                            $set('credit_days', 0);
                            $set('_prefill_source_date', null);
                            $set('taxable_total', 0);
                            $set('isv', 0);
                        }),
                    TextInput::make('supplier_invoice_number')
                        ->label('# documento del proveedor')
                        ->placeholder('000-001-01-00000123')
                        ->default('')
                        // Los guiones aparecen solos; acepta pegar desde PDF con o sin guiones.
                        ->mask('999-999-99-99999999')
                        ->helperText(fn (callable $get) => filled($get('_prefill_source_date'))
                            ? "Prefijo heredado de compra del {$get('_prefill_source_date')}. Escribí solo los 8 dígitos del correlativo y verificá que coincida con la factura actual."
                            : 'Formato SAR: establecimiento-punto-tipo-correlativo')
                        // En RI el número lo genera InternalReceiptNumberGenerator.
                        ->visible(fn (callable $get) => ! SupplierDocumentType::isReciboInterno($get('document_type')))
                        ->required(fn (callable $get) => ! SupplierDocumentType::isReciboInterno($get('document_type')))
                        ->dehydrated(fn (callable $get) => ! SupplierDocumentType::isReciboInterno($get('document_type')))
                        ->maxLength(30)
                        ->regex('/^\d{3}-\d{3}-\d{2}-\d{8}$/')
                        ->validationMessages([
                            'regex' => 'El formato debe ser XXX-XXX-XX-XXXXXXXX (18 dígitos con guiones).',
                        ])
                        ->rules(fn (?Purchase $record, callable $get) => SupplierDocumentType::isReciboInterno($get('document_type'))
                            ? []
                            : [
                                // Un documento del proveedor solo puede estar registrado UNA
                                // vez vigente. Las anuladas y las eliminadas no cuentan: sin
                                // esto, una factura anulada por error (o un borrador heredado
                                // que hay que recapturar) no se podía volver a registrar.
                                Rule::unique('purchases', 'supplier_invoice_number')
                                    ->where('supplier_id', $get('supplier_id'))
                                    ->where('document_type', $get('document_type'))
                                    ->whereNot('status', PurchaseStatus::Anulada->value)
                                    ->whereNull('deleted_at')
                                    ->ignore($record?->id),
                            ])
                        ->columnSpan(2),
                ]),
                TextInput::make('supplier_cai')
                    ->label('CAI')
                    ->placeholder('XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX-XX-XX')
                    ->default('')
                    // Alfanuméricos + guiones automáticos; mayúsculas al persistir.
                    ->mask('******-******-******-******-******-**-**-**')
                    ->helperText(fn (callable $get) => filled($get('_prefill_source_date'))
                        ? "CAI heredado de compra del {$get('_prefill_source_date')}. Verificá que sea el mismo que aparece en esta factura (el proveedor pudo haber renovado)."
                        : 'Código de Autorización de Impresión impreso en la factura.')
                    ->visible(fn (callable $get) => ! SupplierDocumentType::isReciboInterno($get('document_type')))
                    ->required(fn (callable $get) => ! SupplierDocumentType::isReciboInterno($get('document_type')))
                    ->dehydrated(fn (callable $get) => ! SupplierDocumentType::isReciboInterno($get('document_type')))
                    // 36 hexadecimales (6-6-6-6-6-2-2-2) + 7 guiones del formato SAR.
                    ->maxLength(43)
                    ->regex('/^[A-F0-9\-]+$/i')
                    ->validationMessages([
                        'regex' => 'El CAI solo puede contener hexadecimales (0-9, A-F) y guiones.',
                        'max' => 'El CAI no puede exceder 43 caracteres (formato SAR).',
                    ])
                    ->formatStateUsing(fn (?string $state) => $state ? strtoupper($state) : '')
                    ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper(trim($state)) : null),
            ]);
    }

    // ─── 3. Montos del documento ────────────────────────────────────────────

    private static function amountsSection(): Section
    {
        return Section::make('Montos del documento')
            ->icon('heroicon-o-calculator')
            ->description(fn (callable $get) => SupplierDocumentType::isReciboInterno($get('document_type'))
                ? 'Lo que se pagó, tal como aparece en el recibo.'
                : 'Copie los importes tal como vienen impresos en la factura del proveedor.')
            ->schema([
                Grid::make(3)->schema([
                    self::amountInput('exempt_total')
                        ->label(fn (callable $get) => SupplierDocumentType::isReciboInterno($get('document_type'))
                            ? 'Total pagado'
                            : 'Importe exento')
                        ->helperText(fn (callable $get) => SupplierDocumentType::isReciboInterno($get('document_type'))
                            ? 'Recibo Interno: todo se registra como exento, sin ISV.'
                            : 'Productos usados, servicios exentos, combustible.'),
                    self::amountInput('taxable_total')
                        ->label('Importe gravado 15%')
                        ->helperText('Base sin ISV.')
                        ->visible(fn (callable $get) => ! SupplierDocumentType::isReciboInterno($get('document_type')))
                        // Recalcula el ISV sugerido cada vez que cambia la base; el
                        // operador puede corregirlo después para que coincida con
                        // el documento.
                        ->afterStateUpdated(fn ($state, callable $set) => $set(
                            'isv',
                            PurchaseDocumentAmounts::suggestedIsv((float) $state),
                        )),
                    self::amountInput('isv')
                        ->label('ISV 15%')
                        ->helperText('Se calcula solo. Corríjalo si la factura dice otra cifra.')
                        ->visible(fn (callable $get) => ! SupplierDocumentType::isReciboInterno($get('document_type'))),
                ]),
                Placeholder::make('document_total')
                    ->hiddenLabel()
                    ->content(fn (callable $get): HtmlString => self::renderSummary($get))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Campo de monto con las reglas comunes. La validación cruzada (ISV sin
     * gravado, documento en cero…) la decide PurchaseDocumentAmounts — ver
     * amountsRule().
     */
    private static function amountInput(string $field): TextInput
    {
        return TextInput::make($field)
            ->numeric()
            ->required()
            ->default(0)
            ->minValue(0)
            ->step(0.01)
            ->prefix('L')
            ->live(onBlur: true)
            ->rule(self::amountsRule($field));
    }

    /**
     * Regla de validación que delega en el Value Object: una sola fuente de
     * verdad para las reglas de montos, que también protege a los handlers de
     * Create/Edit si el payload llega manipulado.
     *
     * El VO lanza una excepción con el campo culpable; cada input solo reporta
     * el error si le pertenece, así el mensaje aparece bajo el campo correcto.
     */
    private static function amountsRule(string $field): Closure
    {
        return fn (callable $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $field): void {
            try {
                self::amountsFromState($get);
            } catch (MontosDocumentoInvalidosException $e) {
                if ($e->field === $field) {
                    $fail($e->getMessage());
                }
            }
        };
    }

    /**
     * @throws MontosDocumentoInvalidosException
     */
    private static function amountsFromState(callable $get): PurchaseDocumentAmounts
    {
        return PurchaseDocumentAmounts::forDocument(
            SupplierDocumentType::fromState($get('document_type')),
            (float) $get('taxable_total'),
            (float) $get('exempt_total'),
            (float) $get('isv'),
        );
    }

    private static function renderSummary(callable $get): HtmlString
    {
        try {
            $amounts = self::amountsFromState($get);
        } catch (MontosDocumentoInvalidosException) {
            // Mientras el operador todavía está tipeando (todo en 0, etc.)
            // el error ya se muestra en el campo; aquí solo no hay total que mostrar.
            $amounts = null;
        }

        return new HtmlString(
            view('filament.forms.purchase-summary', [
                'amounts' => $amounts,
                'isReciboInterno' => SupplierDocumentType::isReciboInterno($get('document_type')),
            ])->render()
        );
    }

    // ─── 4. Notas ───────────────────────────────────────────────────────────

    private static function notesSection(): Section
    {
        return Section::make('Notas')
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
            ]);
    }

    private static function isExpense(mixed $kind): bool
    {
        return PurchaseKind::fromState($kind) === PurchaseKind::Gasto;
    }

    private static function isCash(mixed $method): bool
    {
        $method = $method instanceof PaymentMethod ? $method : PaymentMethod::tryFrom((string) $method);

        return $method === PaymentMethod::Efectivo;
    }

    // ─── Auto-fill desde el último documento del proveedor ─────────────────

    /**
     * Pre-llena CAI y prefijo del # de documento con los de la última compra
     * confirmada del proveedor. Solo completa campos vacíos para no pisar lo
     * que el operador ya escribió.
     */
    private static function prefillFromLastDocument(int $supplierId, callable $set, callable $get): void
    {
        $alreadyHasCai = filled($get('supplier_cai'));
        $alreadyHasInvoice = filled($get('supplier_invoice_number'));

        if ($alreadyHasCai && $alreadyHasInvoice) {
            return;
        }

        $prefill = app(SupplierDocumentPrefill::class)->forSupplier($supplierId);

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
    }
}

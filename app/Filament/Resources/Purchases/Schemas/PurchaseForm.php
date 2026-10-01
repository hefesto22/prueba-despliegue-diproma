<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseKind;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Models\Establishment;
use App\Models\Purchase;
use App\Models\Supplier;
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
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/**
 * Formulario de Compras — documento del proveedor, mercadería o gasto.
 *
 * Diseño guiado (aprobado 2026-09-29): cuatro pasos numerados en el orden en
 * que el operador tiene la información en la mano, y un resumen fijo a la
 * derecha con el total y sus efectos (Libro de Compras, caja).
 *
 *   1. ¿Qué vas a registrar?      → Mercadería / Gasto operativo
 *   2. ¿Qué documento te dieron?  → Factura con CAI / Recibo sin CAI
 *      Va antes que el proveedor porque decide qué campos se piden.
 *   3. Datos del documento        → concepto, proveedor, fecha, # y CAI
 *   4. Monto y pago
 *
 * Al elegir el tipo se sugiere el documento (PurchaseKind::suggestedDocumentType).
 *
 * Registrar una compra no mueve la caja (2026-09-29): la forma de pago es
 * opcional e informativa.
 *
 * Sin pestañas a propósito: el operador está transcribiendo un documento en
 * papel y necesita verlo completo para compararlo contra el original.
 *
 * Desde la Fase 1 (2026-07-25) la compra no lleva líneas de producto: se
 * transcriben los montos impresos (exento / gravado / ISV) para el Libro de
 * Compras. El inventario entra por la ficha del producto.
 */
class PurchaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Group::make([
                    self::kindSection(),
                    self::documentTypeSection(),
                    self::detailsSection(),
                    self::amountsAndPaymentSection(),
                    self::notesSection(),
                ])->columnSpan(['lg' => 2]),
                Group::make([
                    self::summarySection(),
                ])
                    ->columnSpan(['lg' => 1])
                    // Estilo en línea y no clases de Tailwind: el tema compilado
                    // (public/build) no incluye `sticky` y así no hay que
                    // recompilar assets para un solo uso.
                    ->extraAttributes(['style' => 'position: sticky; top: 5rem; align-self: start;']),
            ]);
    }

    // ─── 1. ¿Qué vas a registrar? ───────────────────────────────────────────

    private static function kindSection(): Section
    {
        return Section::make('1. ¿Qué vas a registrar?')
            ->description('Mercadería: lo que se compra para vender o usar en reparaciones. Gasto: taxi, gasolina, papelería, servicios…')
            ->compact()
            ->schema([
                ToggleButtons::make('kind')
                    ->hiddenLabel()
                    ->options(PurchaseKind::class)
                    ->default(PurchaseKind::Mercaderia->value)
                    ->inline()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set, string $operation): void {
                        // Solo al crear: en una edición el operador ya eligió
                        // el documento a propósito.
                        if ($operation === 'create') {
                            self::applyKindSuggestion($state, $set);
                        }
                    }),
            ]);
    }

    /**
     * Preselecciona el documento habitual del tipo elegido.
     */
    private static function applyKindSuggestion(mixed $kind, callable $set): void
    {
        $documentType = PurchaseKind::fromState($kind)?->suggestedDocumentType();

        if ($documentType === null) {
            return;
        }

        $set('document_type', $documentType->value);
        self::applyDocumentType($documentType->value, $set);
    }

    // ─── 2. ¿Qué documento te dieron? ───────────────────────────────────────

    private static function documentTypeSection(): Section
    {
        return Section::make('2. ¿Qué documento te dieron?')
            ->compact()
            ->schema([
                ToggleButtons::make('document_type')
                    ->hiddenLabel()
                    ->options([
                        // Solo los dos documentos que Diproma recibe en la práctica.
                        // NC/ND de proveedor existen en el enum por estructura SAR
                        // pero no se registran en este negocio.
                        SupplierDocumentType::Factura->value => 'Factura con CAI',
                        SupplierDocumentType::ReciboInterno->value => 'Recibo sin CAI',
                    ])
                    ->icons([
                        SupplierDocumentType::Factura->value => 'heroicon-o-document-check',
                        SupplierDocumentType::ReciboInterno->value => 'heroicon-o-pencil-square',
                    ])
                    ->default(SupplierDocumentType::Factura->value)
                    ->inline()
                    ->required()
                    ->live()
                    ->helperText(fn (callable $get): string => SupplierDocumentType::isReciboInterno($get('document_type'))
                        ? 'Para quien no emite factura (taxi, mercado, particulares). No entra al Libro de Compras ni genera crédito fiscal.'
                        : 'Entra al Libro de Compras y su ISV cuenta como crédito fiscal.')
                    ->afterStateUpdated(fn ($state, callable $set) => self::applyDocumentType($state, $set)),
            ]);
    }

    /**
     * Al pasar a Recibo Interno se limpia lo que deja de aplicar (CAI,
     * # proveedor, gravado, ISV) para que lo validado y lo guardado coincidan.
     * El proveedor NO se limpia: el operador pudo elegirlo a propósito.
     */
    private static function applyDocumentType(mixed $documentType, callable $set): void
    {
        if (! SupplierDocumentType::isReciboInterno($documentType)) {
            return;
        }

        // '' (no null): evita que la máscara de Alpine pinte "null" si el
        // operador vuelve a Factura y el campo reaparece.
        $set('supplier_cai', '');
        $set('supplier_invoice_number', '');
        $set('credit_days', 0);
        $set('_prefill_source_date', null);
        $set('taxable_total', null);
        $set('isv', null);
    }

    // ─── 3. Datos del documento ─────────────────────────────────────────────

    private static function detailsSection(): Section
    {
        return Section::make('3. Datos del documento')
            ->compact()
            ->schema([
                // Flag del auto-fill: solo vive en el cliente para que los helperText
                // del # de documento y del CAI muestren de qué compra se heredaron.
                Hidden::make('_prefill_source_date')
                    ->dehydrated(false),

                // credit_days forzado a 0 mientras Cuentas por Pagar no exista:
                // PurchaseService::confirm() marca Pagada las compras de contado.
                Hidden::make('credit_days')
                    ->default(0)
                    ->dehydrated(),

                // Solo en gastos: qué se pagó y en qué rubro del Reporte de Gastos cae.
                Grid::make(3)
                    ->visible(fn (callable $get): bool => self::isExpense($get('kind')))
                    ->schema([
                        TextInput::make('description')
                            ->label('Concepto')
                            ->placeholder('Ej. Taxi a la SAR, gasolina moto mensajero')
                            ->maxLength(500)
                            ->required(fn (callable $get): bool => self::isExpense($get('kind')))
                            ->columnSpan(2),
                        Select::make('expense_category')
                            ->label('Categoría')
                            ->options(ExpenseCategory::class)
                            ->native(false)
                            ->required(fn (callable $get): bool => self::isExpense($get('kind'))),
                    ]),

                Grid::make(3)->schema([
                    self::supplierSelect()
                        ->columnSpan(fn (): int => self::hasSeveralEstablishments() ? 1 : 2),
                    DatePicker::make('date')
                        ->label('Fecha del documento')
                        ->required()
                        ->default(now())
                        ->native(false),
                    self::establishmentSelect(),
                ]),

                // Solo en factura: en un Recibo Interno el número lo genera
                // InternalReceiptNumberGenerator y no hay CAI.
                Grid::make(3)
                    ->visible(fn (callable $get): bool => ! SupplierDocumentType::isReciboInterno($get('document_type')))
                    ->schema([
                        self::supplierInvoiceNumberInput(),
                        self::caiInput()->columnSpan(2),
                    ]),
            ]);
    }

    private static function supplierSelect(): Select
    {
        return Select::make('supplier_id')
            ->label(fn (callable $get): string => SupplierDocumentType::isReciboInterno($get('document_type'))
                ? 'Proveedor (opcional)'
                : 'Proveedor')
            ->relationship(
                name: 'supplier',
                titleAttribute: 'name',
                // Excluye genéricos: el genérico de RI se asigna solo como
                // fallback cuando el operador no elige proveedor real
                // (ver ResolvesPurchaseDocument::resolveReciboInternoFields).
                modifyQueryUsing: fn ($query) => $query->active()->operational(),
            )
            ->searchable()
            ->preload()
            // Factura: SAR exige proveedor identificado. RI: opcional
            // (trazabilidad interna); vacío cae al genérico.
            ->required(fn (callable $get): bool => ! SupplierDocumentType::isReciboInterno($get('document_type')))
            ->live()
            ->dehydrated()
            ->helperText(fn (callable $get): ?string => SupplierDocumentType::isReciboInterno($get('document_type'))
                ? 'Si lo deja vacío se registra como "Varios / Sin identificar".'
                : null)
            ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                // Crédito a proveedores pausado hasta que exista Cuentas por
                // Pagar: credit_days NO se hereda del proveedor (queda en 0 vía
                // Hidden). Restaurar `$set('credit_days', $supplier->credit_days)`
                // cuando CxP exista.
                if (! $state || SupplierDocumentType::isReciboInterno($get('document_type'))) {
                    return;
                }

                self::prefillFromLastDocument((int) $state, $set, $get);
            });
    }

    /**
     * Con una sola sucursal activa el campo se oculta (siempre sería la
     * misma) pero se sigue enviando con su valor por defecto.
     */
    private static function establishmentSelect(): Select
    {
        return Select::make('establishment_id')
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
            // Solo editable en Borrador: una compra confirmada ya está en el
            // Libro de Compras filtrable por sucursal.
            ->disabled(fn (?Purchase $record) => $record !== null && $record->status !== PurchaseStatus::Borrador)
            ->visible(fn (): bool => self::hasSeveralEstablishments())
            ->dehydrated()
            ->dehydratedWhenHidden();
    }

    private static function hasSeveralEstablishments(): bool
    {
        return once(fn (): bool => Establishment::query()->where('is_active', true)->count() > 1);
    }

    private static function supplierInvoiceNumberInput(): TextInput
    {
        return TextInput::make('supplier_invoice_number')
            ->label('# de factura')
            ->placeholder('000-001-01-00000123')
            ->default('')
            // Los guiones aparecen solos; acepta pegar desde PDF con o sin guiones.
            ->mask('999-999-99-99999999')
            ->helperText(fn (callable $get): ?string => filled($get('_prefill_source_date'))
                ? "Prefijo de la compra del {$get('_prefill_source_date')}: escriba solo los 8 dígitos del correlativo."
                : null)
            ->required(fn (callable $get): bool => ! SupplierDocumentType::isReciboInterno($get('document_type')))
            ->dehydrated(fn (callable $get): bool => ! SupplierDocumentType::isReciboInterno($get('document_type')))
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
                ]);
    }

    private static function caiInput(): TextInput
    {
        return TextInput::make('supplier_cai')
            ->label('CAI')
            ->placeholder('XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX-XX-XX')
            ->default('')
            // Alfanuméricos + guiones automáticos; mayúsculas al persistir.
            ->mask('******-******-******-******-******-**-**-**')
            ->helperText(fn (callable $get): ?string => filled($get('_prefill_source_date'))
                ? "CAI de la compra del {$get('_prefill_source_date')}. Verifique que sea el de esta factura (el proveedor pudo renovarlo)."
                : null)
            ->required(fn (callable $get): bool => ! SupplierDocumentType::isReciboInterno($get('document_type')))
            ->dehydrated(fn (callable $get): bool => ! SupplierDocumentType::isReciboInterno($get('document_type')))
            // 36 hexadecimales (6-6-6-6-6-2-2-2) + 7 guiones del formato SAR.
            ->maxLength(43)
            ->regex('/^[A-F0-9\-]+$/i')
            ->validationMessages([
                'regex' => 'El CAI solo puede contener hexadecimales (0-9, A-F) y guiones.',
                'max' => 'El CAI no puede exceder 43 caracteres (formato SAR).',
            ])
            ->formatStateUsing(fn (?string $state) => $state ? strtoupper($state) : '')
            ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper(trim($state)) : null);
    }

    // ─── 4. Monto y pago ────────────────────────────────────────────────────

    private static function amountsAndPaymentSection(): Section
    {
        return Section::make('4. Monto y pago')
            ->description(fn (callable $get): string => SupplierDocumentType::isReciboInterno($get('document_type'))
                ? 'Lo que se pagó, tal como aparece en el recibo.'
                : 'Copie los importes tal como vienen impresos en la factura.')
            ->compact()
            ->schema([
                Grid::make(3)->schema([
                    self::amountInput('exempt_total')
                        ->label(fn (callable $get): string => SupplierDocumentType::isReciboInterno($get('document_type'))
                            ? 'Total pagado'
                            : 'Importe exento')
                        // En un recibo es el único monto: tiene que venir.
                        ->required(fn (callable $get): bool => SupplierDocumentType::isReciboInterno($get('document_type')))
                        ->helperText(fn (callable $get): ?string => SupplierDocumentType::isReciboInterno($get('document_type'))
                            ? null
                            : 'Usados, servicios exentos, combustible.'),
                    self::amountInput('taxable_total')
                        ->label('Importe gravado 15%')
                        ->helperText('Base sin ISV.')
                        ->visible(fn (callable $get): bool => ! SupplierDocumentType::isReciboInterno($get('document_type')))
                        // Recalcula el ISV sugerido cada vez que cambia la base; el
                        // operador puede corregirlo para que coincida con el documento.
                        ->afterStateUpdated(fn ($state, callable $set) => $set(
                            'isv',
                            blank($state) ? null : PurchaseDocumentAmounts::suggestedIsv((float) $state),
                        )),
                    self::amountInput('isv')
                        ->label('ISV 15%')
                        ->helperText('Se calcula solo; corríjalo si la factura dice otra cifra.')
                        ->visible(fn (callable $get): bool => ! SupplierDocumentType::isReciboInterno($get('document_type'))),
                ]),
                ToggleButtons::make('payment_method')
                    ->label('Forma de pago (opcional)')
                    ->options(PaymentMethod::class)
                    ->inline()
                    ->live()
                    ->helperText('Solo informativa: registrar la compra no mueve la caja.'),
            ]);
    }

    /**
     * Campo de monto con las reglas comunes. Vacío cuenta como 0: el campo
     * no trae un 0 escrito que haya que borrar antes de teclear.
     *
     * La validación cruzada (ISV sin gravado, documento en cero…) la decide
     * PurchaseDocumentAmounts — ver amountsRule(). Si todos quedan vacíos la
     * regla no corre (Laravel omite campos vacíos no obligatorios) y el
     * error lo da ResolvesPurchaseDocument al guardar, bajo el mismo campo.
     */
    private static function amountInput(string $field): TextInput
    {
        return TextInput::make($field)
            ->numeric()
            ->placeholder('0.00')
            // Al editar, un 0 guardado también se muestra vacío.
            ->formatStateUsing(fn ($state) => blank($state) || (float) $state === 0.0 ? null : $state)
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

    // ─── Notas ──────────────────────────────────────────────────────────────

    private static function notesSection(): Section
    {
        return Section::make('Notas')
            ->description('Observaciones internas (opcional).')
            ->compact()
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

    // ─── Resumen (columna derecha) ──────────────────────────────────────────

    private static function summarySection(): Section
    {
        return Section::make('Resumen')
            ->icon('heroicon-o-calculator')
            ->compact()
            ->schema([
                Placeholder::make('document_summary')
                    ->hiddenLabel()
                    ->content(fn (callable $get, ?Purchase $record): HtmlString => self::renderSummary($get, $record)),
            ]);
    }

    /**
     * El total NO es un campo editable: se deriva en PurchaseDocumentAmounts,
     * así que el invariante no se puede romper. El resumen lo muestra junto a
     * lo que pasará al confirmar, para que el operador lo compare contra el
     * documento antes de guardar.
     */
    private static function renderSummary(callable $get, ?Purchase $record): HtmlString
    {
        try {
            $amounts = self::amountsFromState($get);
        } catch (MontosDocumentoInvalidosException) {
            // Mientras el operador todavía está tipeando (todo en 0, etc.)
            // el error ya se muestra en el campo; aquí solo no hay total.
            $amounts = null;
        }

        $supplierId = $get('supplier_id');

        return new HtmlString(
            view('filament.forms.purchase-summary', [
                'amounts' => $amounts,
                'kind' => PurchaseKind::fromState($get('kind')),
                'isReciboInterno' => SupplierDocumentType::isReciboInterno($get('document_type')),
                'paymentMethod' => self::paymentMethodFromState($get('payment_method')),
                'supplierName' => filled($supplierId)
                    ? Supplier::query()->whereKey($supplierId)->value('name')
                    : null,
                'purchaseNumber' => $record?->purchase_number,
            ])->render()
        );
    }

    private static function isExpense(mixed $kind): bool
    {
        return PurchaseKind::fromState($kind) === PurchaseKind::Gasto;
    }

    /**
     * El state llega como string al capturar y como enum al editar (cast del modelo).
     */
    private static function paymentMethodFromState(mixed $state): ?PaymentMethod
    {
        return $state instanceof PaymentMethod ? $state : PaymentMethod::tryFrom((string) $state);
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

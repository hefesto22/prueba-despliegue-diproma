<?php

namespace App\Filament\Actions;

use App\Authorization\CustomPermission;
use App\Enums\PaymentMethod;
use App\Enums\TaxType;
use App\Exceptions\Cash\NoHayCajaAbiertaException;
use App\Models\Customer;
use App\Services\Establishments\EstablishmentResolver;
use App\Services\Establishments\Exceptions\NoActiveEstablishmentException;
use App\Services\Invoicing\InvoiceService;
use App\Services\Sales\FreeSaleLine;
use App\Services\Sales\SaleService;
use App\Services\Sales\Tax\SaleTaxCalculator;
use App\Services\Sales\Tax\TaxableLine;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Botón "Factura casos fuera de manejo de sistema" del Punto de Venta.
 *
 * Abre un modal donde se arma la factura a mano: cliente (RTN + nombre,
 * obligatorios) y líneas libres con descripción, costo, si lleva ISV y
 * precio de venta (con ISV cuando lo lleva). Al confirmar:
 *   - SaleService::processFreeLineSale crea la venta (caja abierta, ingreso
 *     a caja con la forma de pago elegida, cliente auto-creado por RTN).
 *   - InvoiceService::generateFromSale emite la factura CAI.
 * Ambas en UNA transacción: si el CAI falla, no queda venta cobrada sin
 * factura. La factura sale en Ventas, Facturas y el Libro de Ventas como
 * cualquier otra.
 *
 * Visible solo con `Issue:SpecialInvoice`, reservado a super_admin (se lo da
 * CustomPermissionsSeeder; el seeder de roles nunca se lo da a admin).
 * SaleService vuelve a verificar el permiso (defense-in-depth).
 */
final class SpecialInvoiceAction
{
    public const NAME = 'facturaEspecial';

    public static function make(): Action
    {
        return Action::make(self::NAME)
            ->label('Factura casos fuera de manejo de sistema')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can(CustomPermission::IssueSpecialInvoice->value) === true)
            ->modalHeading('Factura casos fuera de manejo de sistema')
            ->modalDescription('Conceptos que no están en el catálogo. Sale con CAI, entra al Libro de Ventas y registra el ingreso en la caja abierta.')
            ->modalIcon(Heroicon::OutlinedDocumentPlus)
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Emitir factura')
            ->closeModalByClickingAway(false)
            ->schema(self::schema())
            ->action(function (
                array $data,
                Action $action,
                SaleService $sales,
                InvoiceService $invoices,
                EstablishmentResolver $establishments,
            ): void {
                try {
                    $lines = self::linesFromState($data['lines'] ?? []);
                    $establishment = $establishments->resolve();

                    [$sale, $invoice] = DB::transaction(function () use ($sales, $invoices, $data, $lines, $establishment): array {
                        $sale = $sales->processFreeLineSale(
                            lines: $lines,
                            paymentMethod: self::paymentMethodFrom($data['payment_method'] ?? null),
                            customerName: (string) ($data['customer_name'] ?? ''),
                            customerRtn: (string) ($data['customer_rtn'] ?? ''),
                            notes: 'Factura especial — casos fuera de manejo de sistema',
                            establishment: $establishment,
                        );

                        $invoice = $invoices->generateFromSale(
                            sale: $sale,
                            establishmentId: $establishment->id,
                        );

                        return [$sale, $invoice];
                    });

                    Notification::make()
                        ->title('Factura emitida')
                        ->body("Factura {$invoice->display_number} — L ".number_format((float) $sale->total, 2))
                        ->success()
                        ->actions([
                            Action::make('ver_factura')
                                ->label('Ver / Imprimir Factura')
                                ->url(route('invoices.print', $invoice))
                                ->openUrlInNewTab()
                                ->button(),
                        ])
                        ->persistent()
                        ->send();
                } catch (NoHayCajaAbiertaException) {
                    self::failAndKeepModalOpen($action, 'No hay caja abierta', 'Abrí la caja del día en la sucursal antes de emitir la factura.');
                } catch (NoActiveEstablishmentException $e) {
                    self::failAndKeepModalOpen($action, 'Sucursal no configurada', $e->getMessage());
                } catch (AuthorizationException|\InvalidArgumentException|\RuntimeException $e) {
                    // RuntimeException cubre las de facturación (CAI vencido,
                    // agotado o sin rango activo) — sus mensajes ya son claros.
                    self::failAndKeepModalOpen($action, 'No se pudo emitir la factura', $e->getMessage());
                }
            });
    }

    /**
     * @return array<int, Section|Placeholder|ToggleButtons>
     */
    private static function schema(): array
    {
        return [
            Section::make('Cliente')
                ->icon(Heroicon::OutlinedUser)
                ->compact()
                ->columns(2)
                ->schema([
                    TextInput::make('customer_rtn')
                        ->label('RTN')
                        ->placeholder('0501-1990-123456')
                        ->required()
                        ->maxLength(20)
                        ->regex('/^[\s-]*(\d[\s-]*){14}$/')
                        ->validationMessages(['regex' => 'El RTN debe tener 14 dígitos (con o sin guiones).'])
                        ->helperText('Si el cliente ya existe, su nombre se llena solo.')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                            if (blank($state) || filled($get('customer_name'))) {
                                return;
                            }

                            $name = self::findCustomerNameByRtn($state);

                            if ($name !== null) {
                                $set('customer_name', $name);
                            }
                        }),
                    TextInput::make('customer_name')
                        ->label('Nombre o razón social')
                        ->required()
                        ->maxLength(255)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase']),
                ]),

            Section::make('Líneas de la factura')
                ->icon(Heroicon::OutlinedListBullet)
                ->compact()
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('description')
                                ->label('Descripción')
                                ->placeholder('Ej: Almohadillas y mantenimiento de Epson L350')
                                ->required()
                                ->maxLength(FreeSaleLine::MAX_DESCRIPTION_LENGTH)
                                ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                                ->columnSpanFull(),
                            TextInput::make('quantity')
                                ->label('Cantidad')
                                ->required()
                                ->numeric()
                                ->integer()
                                ->minValue(1)
                                ->default(1)
                                ->live(onBlur: true),
                            TextInput::make('unit_cost')
                                ->label('Costo (opcional)')
                                ->numeric()
                                ->minValue(0)
                                ->prefix('L')
                                ->helperText('Lo que te costó, sin ISV. Solo para la ganancia.'),
                            Toggle::make('has_isv')
                                ->label('Lleva ISV 15%')
                                ->default(false)
                                ->inline(false)
                                ->live(),
                            TextInput::make('unit_price')
                                ->label(fn (Get $get): string => $get('has_isv') ? 'Precio de venta (con ISV)' : 'Precio de venta')
                                ->required()
                                ->numeric()
                                ->minValue(0.01)
                                ->prefix('L')
                                ->live(onBlur: true),
                        ])
                        ->columns(4)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('Agregar línea')
                        ->reorderable(false)
                        ->itemLabel(fn (array $state): ?string => filled($state['description'] ?? null)
                            ? mb_strtoupper((string) $state['description'], 'UTF-8')
                            : null)
                        ->live(),
                ]),

            ToggleButtons::make('payment_method')
                ->label('Forma de pago')
                ->options(PaymentMethod::class)
                ->default(PaymentMethod::Efectivo->value)
                ->required()
                ->inline()
                ->helperText('Efectivo suma al cajón; los demás quedan en el cuadre por forma de pago.'),

            Placeholder::make('totals')
                ->label('Totales')
                ->content(fn (Get $get): HtmlString => self::renderTotals($get('lines') ?? [])),
        ];
    }

    /**
     * Estado del Repeater → líneas validadas. FreeSaleLine valida cada una y
     * lanza InvalidArgumentException con el detalle si algo no cuadra.
     *
     * @param  array<array-key, array<string, mixed>>  $state
     * @return FreeSaleLine[]
     */
    private static function linesFromState(array $state): array
    {
        $lines = [];

        foreach ($state as $line) {
            $lines[] = new FreeSaleLine(
                description: (string) ($line['description'] ?? ''),
                quantity: (int) ($line['quantity'] ?? 1),
                unitPrice: (float) ($line['unit_price'] ?? 0),
                taxType: ! empty($line['has_isv']) ? TaxType::Gravado15 : TaxType::Exento,
                unitCost: filled($line['unit_cost'] ?? null) ? (float) $line['unit_cost'] : null,
            );
        }

        return $lines;
    }

    /**
     * El state del ToggleButtons llega como string; normalizamos por si
     * Filament lo hidrata como enum.
     */
    private static function paymentMethodFrom(mixed $state): PaymentMethod
    {
        return $state instanceof PaymentMethod
            ? $state
            : PaymentMethod::from((string) ($state ?: PaymentMethod::Efectivo->value));
    }

    /**
     * Busca el cliente por RTN tal como se escribió, solo dígitos o con el
     * formato 0000-0000-000000 — el directorio guarda el RTN como lo tecleó
     * el cajero.
     */
    private static function findCustomerNameByRtn(string $rtn): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $rtn);

        $variants = array_unique(array_filter([
            trim($rtn),
            $digits,
            strlen($digits) === 14
                ? substr($digits, 0, 4).'-'.substr($digits, 4, 4).'-'.substr($digits, 8, 6)
                : null,
        ]));

        return Customer::query()
            ->active()
            ->whereIn('rtn', $variants)
            ->value('name');
    }

    /**
     * Vista previa de totales con el mismo SaleTaxCalculator que persiste la
     * venta: lo que se ve en el modal es lo que sale en la factura.
     *
     * @param  array<array-key, array<string, mixed>>  $lines
     */
    private static function renderTotals(array $lines): HtmlString
    {
        $taxableLines = [];

        foreach ($lines as $line) {
            $price = (float) ($line['unit_price'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 1);

            if ($price <= 0 || $quantity < 1) {
                continue;
            }

            $taxableLines[] = new TaxableLine(
                unitPrice: $price,
                quantity: $quantity,
                taxType: ! empty($line['has_isv']) ? TaxType::Gravado15 : TaxType::Exento,
            );
        }

        $breakdown = app(SaleTaxCalculator::class)->calculate($taxableLines);

        return new HtmlString(sprintf(
            '<div style="display:flex;flex-wrap:wrap;gap:1.5rem;font-variant-numeric:tabular-nums;">'
            .'<span>Subtotal: L %s</span><span>ISV 15%%: L %s</span><strong>Total: L %s</strong></div>',
            number_format($breakdown->subtotal, 2),
            number_format($breakdown->isv, 2),
            number_format($breakdown->total, 2),
        ));
    }

    private static function failAndKeepModalOpen(Action $action, string $title, string $body): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->danger()
            ->persistent()
            ->send();

        // Deja el modal abierto con lo que ya se escribió para corregir y reintentar.
        $action->halt();
    }
}

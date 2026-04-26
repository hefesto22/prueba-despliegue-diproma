<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cash\Actions;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Exceptions\Cash\MovimientoEnSesionCerradaException;
use App\Exceptions\Cash\NoHayCajaAbiertaException;
use App\Models\CashSession;
use App\Services\Expenses\ExpenseService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

/**
 * Action reutilizable de "Registrar gasto" desde el contexto de caja.
 *
 * Reutilizada en ListCashSessions y ViewCashSession. El caller inyecta un
 * `$sessionResolver` que retorna la sesión abierta relevante al contexto, y el
 * `ExpenseService` orquesta la creación del Expense + (si aplica) el
 * CashMovement asociado vía DI explícita desde la Page.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * EVOLUCIÓN VS. VERSIÓN ANTERIOR
 * ─────────────────────────────────────────────────────────────────────────
 * Antes: hardcodeaba `payment_method = Efectivo` y delegaba al CashSessionService
 * directamente. Reflejaba un dominio "caja chica" puro pero impedía registrar
 * gastos pagados con tarjeta/transferencia desde la misma UI — el cajero los
 * tenía que pasar a un admin para que los registre vía otro flow.
 *
 * Ahora: la action permite cualquier `PaymentMethod` y delega a
 * `ExpenseService::register()`. El service decide internamente:
 *   - Si payment_method = Efectivo → crea Expense + CashMovement vinculado
 *     (el saldo del cajón se descuenta).
 *   - Si payment_method ≠ Efectivo → crea SOLO el Expense (registro contable
 *     puro, no afecta saldo físico).
 *
 * Esto centraliza el registro de gastos y elimina la división artificial entre
 * "gastos de caja chica" y "gastos contables" — ambos son la misma entidad
 * de dominio (Expense) con un atributo distinto (payment_method).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * VISIBILIDAD: SIGUE ATADA A SESIÓN ABIERTA
 * ─────────────────────────────────────────────────────────────────────────
 * Aunque ahora soporta no-efectivo, esta action vive en pantallas de caja y
 * sigue requiriendo una sesión abierta en el contexto. Razones:
 *   1) `establishment_id` viene de la sesión resuelta — sin sesión no tenemos
 *      sucursal del gasto.
 *   2) El gasto se registra "estando en caja", aprovechando que el cajero ya
 *      está en su flow operativo. Para registro masivo o histórico → Resource
 *      ExpenseResource (admin/contador, sin dependencia de caja).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DATOS FISCALES: SECCIÓN COLAPSABLE OPCIONAL
 * ─────────────────────────────────────────────────────────────────────────
 * Gastos sin factura (taxi, propinas, gastos menores) son comunes en caja
 * chica y forzar provider/RTN sería fricción innecesaria. Por eso los datos
 * fiscales viven en una Section colapsada — visible cuando el cajero la
 * expande para anotar la factura del proveedor, transparente cuando no.
 *
 * Reglas de validación condicional:
 *   - Si `is_isv_deductible = true` → exige provider_rtn + invoice_number + cai.
 *     Sin esos datos, SAR rechaza el crédito fiscal en una eventual auditoría.
 *   - Si `is_isv_deductible = false` → todos los campos fiscales son opcionales.
 *
 * NO se ocupa de:
 *   - Validar permisos (responsabilidad de la Page/Resource vía Policy Shield).
 *   - Cálculos ni locks (ExpenseService::register() / CashSessionService los aplican).
 */
final class RecordExpenseAction
{
    /**
     * @param  Closure(): ?CashSession  $sessionResolver  Retorna la sesión abierta donde se registra el gasto, o null si no aplica.
     * @param  ExpenseService            $expenses        Servicio de orquestación de gastos (inyectado desde la Page con DI).
     */
    public static function make(Closure $sessionResolver, ExpenseService $expenses): Action
    {
        return Action::make('recordExpense')
            ->label('Registrar gasto')
            ->icon('heroicon-o-banknotes')
            ->color('warning')
            ->visible(fn (): bool => $sessionResolver() !== null)
            ->modalHeading('Registrar gasto')
            ->modalDescription('Registra el gasto. Si se paga con efectivo, el monto sale del cajón y descuenta el saldo esperado al cierre. Si se paga con otro método, queda registrado contablemente sin afectar la caja.')
            ->modalSubmitActionLabel('Registrar gasto')
            ->modalWidth('2xl')
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('amount_total')
                        ->label('Monto total (Lempiras)')
                        ->required()
                        ->numeric()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->prefix('L')
                        ->helperText('Monto total del gasto, ISV incluido si aplica.'),

                    Select::make('payment_method')
                        ->label('Método de pago')
                        ->required()
                        ->options(PaymentMethod::class)
                        ->default(PaymentMethod::Efectivo->value)
                        ->native(false)
                        ->live()
                        ->helperText('Solo "Efectivo" descuenta del cajón físico.'),
                ]),

                Grid::make(2)->schema([
                    Select::make('category')
                        ->label('Categoría')
                        ->required()
                        ->options(ExpenseCategory::class)
                        ->native(false)
                        ->helperText('Agrupación para reportes mensuales.'),

                    DatePicker::make('expense_date')
                        ->label('Fecha del gasto')
                        ->required()
                        ->default(fn (): string => now()->format('Y-m-d'))
                        ->native(false)
                        ->maxDate(now())
                        ->helperText('Default: hoy. Ajustá si registrás con demora.'),
                ]),

                Textarea::make('description')
                    ->label('Descripción')
                    ->required()
                    ->rows(2)
                    ->maxLength(500)
                    ->helperText('Breve — ej. "Gasolina moto mensajero", "Resma papel bond Office Depot".'),

                // ── Datos fiscales del proveedor (opcional) ─────────────
                // Colapsada por default: la mayoría de gastos menores no tienen
                // factura. Cuando hay (gasolina, papelería, mantenimiento), el
                // cajero/contador la expande y carga RTN/CAI/número de factura.
                //
                // Si toggle is_isv_deductible se activa, los campos clave del
                // SAR (RTN, número de factura, CAI) pasan a ser obligatorios:
                // sin ellos no se puede sostener el crédito fiscal ante una
                // auditoría.
                Section::make('Datos fiscales del proveedor')
                    ->description('Opcional — completá si el gasto tiene factura del proveedor.')
                    ->icon('heroicon-o-document-text')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('provider_name')
                                ->label('Proveedor')
                                ->maxLength(200)
                                ->placeholder('Ej. Uno Honduras, Office Depot, Taller Mendoza'),

                            TextInput::make('provider_rtn')
                                ->label('RTN del proveedor')
                                ->maxLength(14)
                                ->minLength(14)
                                ->regex('/^\d{14}$/')
                                ->requiredIf('is_isv_deductible', true)
                                // Mensajes custom: evitamos el placeholder :attribute
                                // porque Filament aplica Str::lcfirst() al label, y
                                // "RTN" → "rTN" queda mal escrito. Mensaje explícito
                                // sin placeholder soluciona y de paso evita el "es true"
                                // genérico que Laravel inyecta para required_if.
                                ->validationMessages([
                                    'regex' => 'El RTN debe tener exactamente 14 dígitos sin guiones.',
                                    'required_if' => 'El RTN del proveedor es obligatorio si el gasto se marca como deducible de ISV.',
                                ])
                                ->placeholder('06459877498120')
                                ->helperText('Obligatorio si se marca como deducible de ISV.'),
                        ]),

                        Grid::make(2)->schema([
                            TextInput::make('provider_invoice_number')
                                ->label('Número de factura')
                                ->maxLength(50)
                                ->requiredIf('is_isv_deductible', true)
                                ->validationMessages([
                                    'required_if' => 'El número de factura es obligatorio si el gasto se marca como deducible de ISV.',
                                ])
                                ->placeholder('000-001-01-00001234'),

                            DatePicker::make('provider_invoice_date')
                                ->label('Fecha de la factura')
                                ->native(false)
                                ->maxDate(now()),
                        ]),

                        TextInput::make('provider_invoice_cai')
                            ->label('CAI del proveedor')
                            // 43 = 36 hexadecimales (6-6-6-6-6-2-2-2) + 7 guiones del
                            // formato oficial SAR. La columna en BD acepta hasta 50 (margen
                            // por si SAR cambia formato en el futuro).
                            ->maxLength(43)
                            ->mask('******-******-******-******-******-**-**-**')
                            ->placeholder('XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX-XX-XX')
                            ->regex('/^[A-F0-9\-]+$/i')
                            ->requiredIf('is_isv_deductible', true)
                            ->validationMessages([
                                'regex' => 'El CAI solo puede contener hexadecimales (0-9, A-F) y guiones.',
                                'required_if' => 'El CAI del proveedor es obligatorio si el gasto se marca como deducible de ISV.',
                                // 'max' explícito: sin esto Filament inyecta el label
                                // ("CAI") con Str::lcfirst() y queda "cAI" — feo y poco
                                // profesional. Mensaje hardcodeado lo evita.
                                'max' => 'El CAI no puede exceder 43 caracteres (formato SAR).',
                            ])
                            ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper(trim($state)) : null)
                            ->helperText('Código de Autorización de Impresión que aparece en la factura del proveedor.'),

                        Grid::make(2)->schema([
                            TextInput::make('isv_amount')
                                ->label('ISV desglosado (Lempiras)')
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01)
                                ->prefix('L')
                                ->helperText('Monto de ISV indicado en la factura, si lo desglosa.'),

                            Toggle::make('is_isv_deductible')
                                ->label('Deducible de ISV')
                                ->live()
                                ->default(false)
                                ->helperText('Marcar si el gasto genera crédito fiscal — exige RTN, factura y CAI.'),
                        ]),
                    ]),
            ])
            ->action(fn (array $data) => self::handle($sessionResolver, $expenses, $data));
    }

    /**
     * Handler del submit. Resuelve la sesión al momento del submit — no al
     * abrir el modal — por si fue cerrada entre ambos eventos.
     *
     * Estrategia:
     *   1) Validar que aún hay sesión abierta (visibilidad podía estar caché).
     *   2) Construir attributes para ExpenseService::register() — el service
     *      decide si crea CashMovement basándose en payment_method.
     *   3) Notificar diferenciado según afecte caja o no.
     *
     * Manejo de excepciones:
     *   - NoHayCajaAbiertaException: solo dispara con Efectivo si la sesión se
     *     cerró entre el check de visibilidad y el lock del service.
     *   - MovimientoEnSesionCerradaException: defense in depth, idéntico caso.
     *   - Throwable: error inesperado, reportar y mostrar mensaje genérico.
     *
     * @param  array<string, mixed>  $data
     */
    private static function handle(Closure $sessionResolver, ExpenseService $expenses, array $data): void
    {
        $session = $sessionResolver();

        if ($session === null) {
            Notification::make()
                ->title('No hay sesión abierta')
                ->body('La sesión fue cerrada por otro usuario. Recargá la pantalla.')
                ->warning()
                ->send();

            return;
        }

        try {
            // Filament v4 Select::make(...)->options(BackedEnum::class) hidrata
            // el valor como instancia del enum. Pasamos $data['category'] y
            // $data['payment_method'] tal cual — los casts en Expense
            // serializan al string al guardar, sea instancia o string crudo.
            $expense = $expenses->register([
                'establishment_id'        => $session->establishment_id,
                'user_id'                 => auth()->id(),
                'expense_date'            => $data['expense_date'],
                'category'                => $data['category'],
                'payment_method'          => $data['payment_method'],
                'amount_total'            => (float) $data['amount_total'],
                'isv_amount'              => isset($data['isv_amount']) && $data['isv_amount'] !== ''
                    ? (float) $data['isv_amount']
                    : null,
                'is_isv_deductible'       => (bool) ($data['is_isv_deductible'] ?? false),
                'description'             => $data['description'],
                'provider_name'           => $data['provider_name'] ?? null,
                'provider_rtn'            => $data['provider_rtn'] ?? null,
                'provider_invoice_number' => $data['provider_invoice_number'] ?? null,
                'provider_invoice_cai'    => $data['provider_invoice_cai'] ?? null,
                'provider_invoice_date'   => $data['provider_invoice_date'] ?? null,
            ]);

            // Mensaje diferenciado: el cajero necesita saber si el monto SALIÓ
            // del cajón (Efectivo) o solo quedó como registro contable. Sin
            // esa diferencia, el cuadre al cierre puede confundirlo.
            $affectsCash = $expense->affectsCashBalance();
            $body = sprintf(
                'Gasto #%d · L. %s · %s · %s',
                $expense->id,
                number_format((float) $expense->amount_total, 2),
                $expense->category?->getLabel() ?? '—',
                $affectsCash
                    ? 'descontado del cajón'
                    : 'registrado contablemente, no afecta caja',
            );

            Notification::make()
                ->title('Gasto registrado')
                ->body($body)
                ->success()
                ->send();
        } catch (NoHayCajaAbiertaException $e) {
            // Race: la sesión se cerró entre resolver() y el lock del service.
            // Solo aplica con Efectivo — los demás métodos no tocan caja.
            Notification::make()
                ->title('No hay caja abierta')
                ->body('La caja fue cerrada antes de registrar el gasto en efectivo. Abrí una nueva sesión o registralo con otro método de pago.')
                ->warning()
                ->send();
        } catch (MovimientoEnSesionCerradaException $e) {
            // Defense in depth — normalmente NoHayCajaAbierta dispara primero.
            Notification::make()
                ->title('Sesión ya cerrada')
                ->body('No se pueden registrar movimientos en sesiones cerradas.')
                ->warning()
                ->send();
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title('Error al registrar gasto')
                ->body('No se pudo registrar el gasto. Revisá los logs y volvé a intentar.')
                ->danger()
                ->send();
        }
    }
}

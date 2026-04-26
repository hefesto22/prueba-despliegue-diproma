<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Form de edición de Expense (admin/contador).
 *
 * No se usa para Create: el Resource no expone Create page (creación canónica
 * vive en `RecordExpenseAction` desde caja). Por eso este form asume que el
 * record ya existe y separa los campos en dos grupos visuales:
 *
 *   1. Datos estructurales (READ-ONLY) — establishment, user, expense_date,
 *      payment_method, amount_total. Cambiarlos requiere mover el kardex de
 *      caja u otra trazabilidad. Si hay error real, se anula y se re-emite.
 *
 *   2. Datos fiscales y descriptivos (EDITABLE) — description, category,
 *      provider_*, isv_amount, is_isv_deductible. Es lo que el contador
 *      corrige al revisar el cierre mensual antes de declarar.
 *
 * Validación condicional:
 *   - Si is_isv_deductible = true → exige RTN, número de factura y CAI.
 *     Mismo criterio que en RecordExpenseAction — sin esos datos SAR rechaza
 *     el crédito fiscal en una eventual auditoría.
 */
class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([

                // ── 1. Datos estructurales (NO editables) ────────────
                Section::make('Datos del registro')
                    ->aside()
                    ->description('Estos datos se fijaron al registrar el gasto. Si hay error, anulá el gasto y registralo de nuevo desde caja.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('establishment.name')
                                ->label('Sucursal')
                                ->disabled()
                                ->dehydrated(false),

                            TextInput::make('user.name')
                                ->label('Registrado por')
                                ->disabled()
                                ->dehydrated(false),
                        ]),

                        Grid::make(3)->schema([
                            DatePicker::make('expense_date')
                                ->label('Fecha del gasto')
                                ->disabled()
                                ->dehydrated(false)
                                ->native(false),

                            Select::make('payment_method')
                                ->label('Método de pago')
                                ->options(PaymentMethod::class)
                                ->disabled()
                                ->dehydrated(false)
                                ->native(false),

                            TextInput::make('amount_total')
                                ->label('Monto total')
                                ->prefix('L')
                                ->disabled()
                                ->dehydrated(false),
                        ]),
                    ]),

                // ── 2. Datos descriptivos (EDITABLES) ────────────────
                Section::make('Descripción y categoría')
                    ->aside()
                    ->description('Editable. Corregí la categoría o descripción si fue mal cargada al registrarse.')
                    ->schema([
                        Select::make('category')
                            ->label('Categoría')
                            ->required()
                            ->options(ExpenseCategory::class)
                            ->native(false)
                            ->helperText('Agrupación para reportes mensuales.'),

                        Textarea::make('description')
                            ->label('Descripción')
                            ->required()
                            ->rows(2)
                            ->maxLength(500),
                    ]),

                // ── 3. Datos fiscales del proveedor (EDITABLES) ──────
                // No colapsable acá (a diferencia del modal de caja): el
                // contador entra explícitamente a editar gastos cuando va a
                // revisar fiscales del mes — no agregamos fricción extra.
                Section::make('Datos fiscales del proveedor')
                    ->aside()
                    ->description('Completá si el gasto tiene factura. Obligatorio si se marca como deducible de ISV.')
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
                                ->validationMessages([
                                    'regex' => 'El RTN debe tener exactamente 14 dígitos sin guiones.',
                                    'required_if' => 'El RTN del proveedor es obligatorio si el gasto se marca como deducible de ISV.',
                                ])
                                ->placeholder('06459877498120'),
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
                            // formato oficial SAR. La columna en BD acepta hasta 50.
                            ->maxLength(43)
                            ->mask('******-******-******-******-******-**-**-**')
                            ->placeholder('XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX-XX-XX')
                            ->regex('/^[A-F0-9\-]+$/i')
                            ->requiredIf('is_isv_deductible', true)
                            ->validationMessages([
                                'regex' => 'El CAI solo puede contener hexadecimales (0-9, A-F) y guiones.',
                                'required_if' => 'El CAI del proveedor es obligatorio si el gasto se marca como deducible de ISV.',
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
            ]);
    }
}

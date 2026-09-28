<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Expenses\Schemas\Components\ExpenseFiscalSection;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
 * Validación condicional: la sección fiscal es la misma del modal de caja
 * (ExpenseFiscalSection). Con "Factura con CAI" el gasto se copia al Libro de
 * Compras al guardar — ver EditExpense y ExpenseFiscalDocumentSync.
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
                            ->options(
                                // Excluimos las categorías auto-generadas por el sistema
                                // (ej. ComisionesBancarias). Esos gastos los crea el
                                // CardFeeRecorder al cobrar con tarjeta — no deberían
                                // poder elegirse manualmente para mantener la integridad
                                // del filtro "comisiones del mes" en reportes.
                                collect(ExpenseCategory::cases())
                                    ->reject(fn (ExpenseCategory $c) => $c->isSystemGenerated())
                                    ->mapWithKeys(fn (ExpenseCategory $c) => [$c->value => $c->getLabel()])
                                    ->all()
                            )
                            ->native(false)
                            ->helperText('Agrupación para reportes mensuales.'),

                        Textarea::make('description')
                            ->label('Descripción')
                            ->required()
                            ->rows(2)
                            ->maxLength(500),
                    ]),

                // ── 3. Datos fiscales del proveedor (EDITABLES) ──────
                // Misma sección que el modal de caja (ExpenseFiscalSection), sin
                // colapsar: el contador entra aquí justamente a revisar fiscales.
                ExpenseFiscalSection::make(collapsed: false),
            ]);
    }
}

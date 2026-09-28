<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Schemas\Components;

use App\Models\Expense;
use App\Services\Expenses\ExpenseFiscalDocumentSync;
use App\Services\Purchases\Exceptions\MontosDocumentoInvalidosException;
use App\Services\Purchases\PurchaseDocumentAmounts;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

/**
 * Sección "Datos fiscales del proveedor" de un gasto — única definición para
 * el modal "Registrar gasto" de caja (RecordExpenseAction) y la edición del
 * gasto (ExpenseForm). Antes estaba copiada en ambos lugares.
 *
 * Con el switch "Factura con CAI" encendido, el gasto se copia al Libro de
 * Compras (ExpenseFiscalDocumentSync), así que se exigen los datos que el
 * libro necesita: proveedor, RTN, número, CAI e importe gravado. El ISV se
 * sugiere al 15% del gravado y el exento se deriva del total.
 *
 * Gastos heredados (deducibles antes de la Fase 1b, sin gravado): no se les
 * exige el gravado para que editar su descripción no obligue a mandarlos al
 * libro. Si alguien les carga el gravado, pasan a validarse y sincronizarse.
 */
final class ExpenseFiscalSection
{
    public static function make(bool $collapsed): Section
    {
        $section = Section::make('Datos fiscales del proveedor')
            ->icon('heroicon-o-document-text')
            ->schema([
                Toggle::make('is_isv_deductible')
                    ->label('Factura con CAI — enviar al Libro de Compras')
                    ->live()
                    ->default(false)
                    ->helperText('El gasto se registra también en Compras y su ISV cuenta como crédito fiscal. Exige proveedor, RTN, número de factura, CAI e importe gravado.'),

                Grid::make(2)->schema([
                    TextInput::make('provider_name')
                        ->label('Proveedor')
                        ->maxLength(200)
                        ->requiredIf('is_isv_deductible', true)
                        ->validationMessages([
                            'required_if' => 'El nombre del proveedor es obligatorio si el gasto trae factura con CAI.',
                        ])
                        ->placeholder('Ej. Uno Honduras, Office Depot, Taller Mendoza'),

                    TextInput::make('provider_rtn')
                        ->label('RTN del proveedor')
                        ->maxLength(14)
                        ->minLength(14)
                        ->regex('/^\d{14}$/')
                        ->requiredIf('is_isv_deductible', true)
                        // Mensajes sin :attribute: Filament aplica lcfirst() al label
                        // y "RTN" quedaría "rTN".
                        ->validationMessages([
                            'regex' => 'El RTN debe tener exactamente 14 dígitos sin guiones.',
                            'required_if' => 'El RTN del proveedor es obligatorio si el gasto trae factura con CAI.',
                        ])
                        ->placeholder('06459877498120'),
                ]),

                Grid::make(2)->schema([
                    TextInput::make('provider_invoice_number')
                        ->label('Número de factura')
                        // 30 = tamaño de purchases.supplier_invoice_number, adonde se copia.
                        ->maxLength(fn (callable $get): int => $get('is_isv_deductible') ? 30 : 50)
                        ->requiredIf('is_isv_deductible', true)
                        ->validationMessages([
                            'required_if' => 'El número de factura es obligatorio si el gasto trae factura con CAI.',
                        ])
                        ->placeholder('000-001-01-00001234'),

                    DatePicker::make('provider_invoice_date')
                        ->label('Fecha de la factura')
                        ->native(false)
                        ->maxDate(now())
                        ->helperText('Define el mes del Libro de Compras. Vacía = fecha del gasto.'),
                ]),

                TextInput::make('provider_invoice_cai')
                    ->label('CAI del proveedor')
                    // 36 hexadecimales (6-6-6-6-6-2-2-2) + 7 guiones del formato SAR.
                    ->maxLength(43)
                    ->mask('******-******-******-******-******-**-**-**')
                    ->placeholder('XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX-XX-XX')
                    ->regex('/^[A-F0-9\-]+$/i')
                    ->requiredIf('is_isv_deductible', true)
                    ->validationMessages([
                        'regex' => 'El CAI solo puede contener hexadecimales (0-9, A-F) y guiones.',
                        'required_if' => 'El CAI del proveedor es obligatorio si el gasto trae factura con CAI.',
                        'max' => 'El CAI no puede exceder 43 caracteres (formato SAR).',
                    ])
                    ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper(trim($state)) : null)
                    ->helperText('Código de Autorización de Impresión que aparece en la factura del proveedor.'),

                Grid::make(3)->schema([
                    TextInput::make('taxable_amount')
                        ->label('Importe gravado 15%')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->prefix('L')
                        ->helperText('Base sin ISV, como viene en la factura.')
                        ->visible(fn (callable $get): bool => (bool) $get('is_isv_deductible'))
                        ->required(fn (callable $get, $record): bool => (bool) $get('is_isv_deductible') && ! self::isLegacy($record, $get))
                        ->validationMessages([
                            'required' => 'El importe gravado es obligatorio si el gasto trae factura con CAI (0 si todo es exento).',
                        ])
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, callable $set) => $set(
                            'isv_amount',
                            PurchaseDocumentAmounts::suggestedIsv((float) $state),
                        ))
                        ->rule(self::amountsRule('taxable_amount')),

                    TextInput::make('isv_amount')
                        ->label('ISV de la factura')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->prefix('L')
                        ->helperText('Se calcula solo; corríjalo si la factura dice otra cifra.')
                        ->live(onBlur: true)
                        ->rule(self::amountsRule('isv_amount')),

                    Placeholder::make('exempt_preview')
                        ->label('Importe exento')
                        ->visible(fn (callable $get): bool => (bool) $get('is_isv_deductible'))
                        ->content(fn (callable $get): string => 'L '.number_format(
                            max(0, (float) $get('amount_total') - (float) $get('taxable_amount') - (float) $get('isv_amount')),
                            2,
                        ))
                        ->helperText('Total del gasto − gravado − ISV.'),
                ]),
            ]);

        return $collapsed
            ? $section
                ->description('Opcional — completá si el gasto tiene factura del proveedor.')
                ->collapsible()
                ->collapsed()
            : $section
                ->aside()
                ->description('Completá si el gasto tiene factura. Con "Factura con CAI" el gasto pasa al Libro de Compras.');
    }

    /**
     * Gasto deducible anterior a la Fase 1b al que todavía no se le cargó el
     * gravado. `$record` no se tipa: en el modal de caja Filament puede
     * inyectar la CashSession de la página, no un Expense.
     */
    private static function isLegacy(mixed $record, callable $get): bool
    {
        return $record instanceof Expense
            && $record->isLegacyDeductible()
            && blank($get('taxable_amount'));
    }

    /**
     * Valida los montos con las mismas reglas que aplica
     * ExpenseFiscalDocumentSync al guardar; cada campo reporta solo su error.
     */
    private static function amountsRule(string $field): Closure
    {
        return fn (callable $get, $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $field): void {
            if (! $get('is_isv_deductible') || self::isLegacy($record, $get)) {
                return;
            }

            try {
                ExpenseFiscalDocumentSync::amountsFor(
                    (float) $get('amount_total'),
                    (float) $get('taxable_amount'),
                    (float) $get('isv_amount'),
                );
            } catch (MontosDocumentoInvalidosException $e) {
                if ($e->field === $field) {
                    $fail($e->getMessage());
                }
            }
        };
    }
}

<?php

namespace App\Services\Expenses;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Services\Cash\CashSessionService;
use Illuminate\Support\Facades\DB;

/**
 * Service de orquestación para la creación de gastos contables.
 *
 * Responsabilidad:
 *   - Crear el Expense (registro fiscal/contable)
 *   - Si payment_method = Efectivo, crear el CashMovement vinculado y
 *     atomicamente asociar ambas filas (Expense.id ↔ CashMovement.expense_id)
 *   - Si payment_method ≠ Efectivo, NO crear CashMovement (no afecta caja)
 *
 * Por qué esta separación:
 *   - SRP: el ExpenseService sabe del dominio "gasto contable"; el
 *     CashSessionService sabe del kardex de caja. Ambos colaboran via
 *     interfaz pública sin acoplarse internamente.
 *   - Las reglas de caja (lock de sesión abierta, validación de cierre) viven
 *     en CashSessionService::recordMovementWithinTransaction y se reutilizan.
 *
 * Atomicidad:
 *   - Si la creación del CashMovement falla (ej. NoHayCajaAbierta), el
 *     Expense entero hace rollback. Garantizado por DB::transaction wrapping
 *     toda la operación.
 *   - El CashMovement nace con expense_id ya seteado (no requiere un UPDATE
 *     posterior). Eso evita la posibilidad de un Expense huérfano si el
 *     proceso falla entre el create del Expense y el update del movement.
 */
class ExpenseService
{
    public function __construct(
        private readonly CashSessionService $cashSessions,
    ) {}

    /**
     * Registra un gasto contable.
     *
     * Si payment_method = Efectivo, además registra el CashMovement
     * asociado en la sesión de caja abierta de la sucursal.
     *
     * Atributos esperados (no se documentan via array_shape para mantener
     * el contrato dinámico — el FormRequest del caller valida la forma):
     *
     *   - establishment_id        (int, requerido)
     *   - user_id                 (int, requerido)
     *   - expense_date            (date, requerido)
     *   - category                (ExpenseCategory|string, requerido)
     *   - payment_method          (PaymentMethod|string, requerido)
     *   - amount_total            (float, requerido, > 0)
     *   - description             (string, requerido)
     *   - isv_amount              (float|null)
     *   - is_isv_deductible       (bool, default false)
     *   - provider_name           (string|null)
     *   - provider_rtn            (string|null)
     *   - provider_invoice_number (string|null)
     *   - provider_invoice_cai    (string|null)
     *   - provider_invoice_date   (date|null)
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws \App\Exceptions\Cash\NoHayCajaAbiertaException
     *         Si payment_method = Efectivo y no hay caja abierta en la sucursal.
     * @throws \App\Exceptions\Cash\MovimientoEnSesionCerradaException
     *         Defense in depth — si la sesión se cerró entre el lock y el insert.
     */
    public function register(array $attributes): Expense
    {
        return DB::transaction(function () use ($attributes) {
            $expense = Expense::create($attributes);

            // Solo gastos en efectivo afectan el kardex de caja. Resto de
            // métodos (tarjeta, transferencia, cheque) viven solo como
            // Expense — el saldo físico del cajón no cambia.
            if ($this->isCashPayment($expense->payment_method)) {
                $this->cashSessions->recordMovementWithinTransaction(
                    establishmentId: $expense->establishment_id,
                    attributes: [
                        'user_id'        => $expense->user_id,
                        'type'           => CashMovementType::Expense,
                        'payment_method' => PaymentMethod::Efectivo,
                        'amount'         => (float) $expense->amount_total,
                        'category'       => $expense->category,
                        'description'    => $expense->description,
                        'occurred_at'    => $expense->expense_date,
                        'expense_id'     => $expense->id,
                    ],
                );
            }

            return $expense->fresh(['cashMovement']);
        });
    }

    /**
     * ¿El método de pago afecta el saldo de caja física?
     *
     * Acepta enum o string crudo (Filament Select::options(BackedEnum::class)
     * hidrata como instancia, FormRequests viejos pueden pasar string).
     */
    private function isCashPayment(PaymentMethod|string $method): bool
    {
        $enum = $method instanceof PaymentMethod
            ? $method
            : PaymentMethod::from($method);

        return $enum->affectsCashBalance();
    }
}

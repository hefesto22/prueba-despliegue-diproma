<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Tipo de movimiento de caja.
 *
 * El `amount` en `cash_movements` siempre es positivo. El signo contable
 * (entra o sale de caja) lo determina este enum vía `isInflow()` e
 * `isOutflow()`. Esto evita bugs de doble-negación y hace cada movimiento
 * inequívocamente legible.
 *
 * Flujos:
 *   - Inflows (entra plata): opening_balance, sale_income
 *   - Outflows (sale plata): expense, supplier_payment, deposit, sale_cancellation
 *   - Neutros/técnicos: closing_balance (solo registro), adjustment (puede
 *     ser cualquiera de los dos — el service aplica signo según contexto)
 *
 * `opening_balance` y `closing_balance` son asentamientos automáticos creados
 * por `CashSessionService` al abrir/cerrar. `sale_income` lo genera
 * `SaleService::processSale()` y `sale_cancellation` lo genera
 * `SaleService::cancel()`. Ninguno de los cuatro se crea manualmente desde UI.
 */
enum CashMovementType: string implements HasLabel, HasColor, HasIcon
{
    case OpeningBalance = 'opening_balance';
    case SaleIncome = 'sale_income';
    case SaleCancellation = 'sale_cancellation';
    case Expense = 'expense';
    case SupplierPayment = 'supplier_payment';
    case Deposit = 'deposit';
    case Adjustment = 'adjustment';
    case ClosingBalance = 'closing_balance';

    public function getLabel(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Apertura',
            self::SaleIncome => 'Ingreso por venta',
            self::SaleCancellation => 'Anulación de venta',
            self::Expense => 'Gasto',
            self::SupplierPayment => 'Pago a proveedor',
            self::Deposit => 'Depósito bancario',
            self::Adjustment => 'Ajuste',
            self::ClosingBalance => 'Cierre',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::OpeningBalance => 'gray',
            self::SaleIncome => 'success',
            self::SaleCancellation => 'danger',
            self::Expense => 'danger',
            self::SupplierPayment => 'danger',
            self::Deposit => 'warning',
            self::Adjustment => 'info',
            self::ClosingBalance => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::OpeningBalance => 'heroicon-o-lock-open',
            self::SaleIncome => 'heroicon-o-arrow-down-circle',
            self::SaleCancellation => 'heroicon-o-arrow-uturn-left',
            self::Expense => 'heroicon-o-arrow-up-circle',
            self::SupplierPayment => 'heroicon-o-truck',
            self::Deposit => 'heroicon-o-building-library',
            self::Adjustment => 'heroicon-o-adjustments-horizontal',
            self::ClosingBalance => 'heroicon-o-lock-closed',
        };
    }

    /**
     * ¿Este tipo representa una entrada de efectivo a caja?
     *
     * Solo aplica cuando el movimiento es con payment_method=efectivo.
     * Usar en conjunto con PaymentMethod::affectsCashBalance().
     */
    public function isInflow(): bool
    {
        return match ($this) {
            self::OpeningBalance, self::SaleIncome => true,
            default => false,
        };
    }

    /**
     * ¿Este tipo representa una salida de efectivo de caja?
     *
     * `SaleCancellation` es outflow porque anular una venta devuelve dinero
     * al cliente (si fue en efectivo, sale del cajón; si fue en tarjeta, el
     * movimiento queda como registro contable pero `affectsCashBalance()` lo
     * filtra en el calculator por el payment_method).
     */
    public function isOutflow(): bool
    {
        return match ($this) {
            self::Expense, self::SupplierPayment, self::Deposit, self::SaleCancellation => true,
            default => false,
        };
    }

    /**
     * ¿Este tipo requiere categoría obligatoria?
     *
     * Los gastos necesitan categoría para reportes; lo demás no.
     */
    public function requiresCategory(): bool
    {
        return $this === self::Expense;
    }

    /**
     * Tipos que el usuario puede crear manualmente desde UI.
     *
     * Opening/ClosingBalance los genera el service automáticamente.
     * SaleIncome lo genera el SaleService al completar una venta.
     *
     * @return list<self>
     */
    public static function userCreatable(): array
    {
        return [
            self::Expense,
            self::SupplierPayment,
            self::Deposit,
            self::Adjustment,
        ];
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Método de pago usado en un movimiento de caja o venta.
 *
 * Regla crítica del dominio: SOLO `efectivo` afecta el saldo físico de caja.
 * Los demás métodos (tarjeta, transferencia, cheque) se registran para
 * reportes fiscales y cuadre de totales, pero no entran ni salen del cajón.
 *
 * Esta regla la implementa `affectsCashBalance()` — cualquier cálculo de
 * saldo de caja DEBE consultar este método, nunca comparar strings a mano.
 */
enum PaymentMethod: string implements HasLabel, HasColor, HasIcon
{
    case Efectivo = 'efectivo';
    case TarjetaCredito = 'tarjeta_credito';
    case TarjetaDebito = 'tarjeta_debito';
    case Transferencia = 'transferencia';
    case Cheque = 'cheque';

    public function getLabel(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::TarjetaCredito => 'Tarjeta de crédito',
            self::TarjetaDebito => 'Tarjeta de débito',
            self::Transferencia => 'Transferencia',
            self::Cheque => 'Cheque',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Efectivo => 'success',
            self::TarjetaCredito => 'info',
            self::TarjetaDebito => 'info',
            self::Transferencia => 'primary',
            self::Cheque => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Efectivo => 'heroicon-o-banknotes',
            self::TarjetaCredito => 'heroicon-o-credit-card',
            self::TarjetaDebito => 'heroicon-o-credit-card',
            self::Transferencia => 'heroicon-o-arrows-right-left',
            self::Cheque => 'heroicon-o-document-text',
        };
    }

    /**
     * ¿Este método afecta el saldo físico de caja?
     *
     * Única fuente de verdad para el cálculo de expected_closing_amount.
     * Tarjeta/transferencia/cheque se registran pero no alteran el cajón.
     */
    public function affectsCashBalance(): bool
    {
        return $this === self::Efectivo;
    }
}

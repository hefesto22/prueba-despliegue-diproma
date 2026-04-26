<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Flujo de estados de una venta:
 *
 * pendiente → completada (descuenta stock, registra kardex)
 * pendiente → anulada (sin efecto en inventario)
 * completada → anulada (devuelve stock, registra kardex de entrada)
 *
 * No existe "borrador" como en compras porque el POS
 * trabaja con un carrito en memoria — la venta se crea
 * directamente al procesar.
 */
enum SaleStatus: string implements HasLabel, HasColor, HasIcon
{
    case Pendiente = 'pendiente';
    case Completada = 'completada';
    case Anulada = 'anulada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Completada => 'Completada',
            self::Anulada => 'Anulada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendiente => 'warning',
            self::Completada => 'success',
            self::Anulada => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pendiente => 'heroicon-o-clock',
            self::Completada => 'heroicon-o-check-circle',
            self::Anulada => 'heroicon-o-x-circle',
        };
    }

    /**
     * ¿Se puede completar desde este estado?
     */
    public function canComplete(): bool
    {
        return $this === self::Pendiente;
    }

    /**
     * ¿Se puede anular desde este estado?
     */
    public function canCancel(): bool
    {
        return $this !== self::Anulada;
    }
}

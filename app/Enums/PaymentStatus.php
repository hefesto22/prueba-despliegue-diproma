<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado de pago de una compra.
 * Aplica principalmente para compras a crédito.
 */
enum PaymentStatus: string implements HasLabel, HasColor
{
    case Pendiente = 'pendiente';
    case Parcial = 'parcial';
    case Pagada = 'pagada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Parcial => 'Parcial',
            self::Pagada => 'Pagada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendiente => 'danger',
            self::Parcial => 'warning',
            self::Pagada => 'success',
        };
    }
}

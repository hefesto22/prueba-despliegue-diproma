<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Flujo de estados de una compra:
 *
 * borrador → confirmada (incrementa stock, actualiza costo promedio)
 * borrador → anulada (sin efecto en inventario)
 * confirmada → anulada (reversa stock, NO reversa costo promedio)
 */
enum PurchaseStatus: string implements HasLabel, HasColor, HasIcon
{
    case Borrador = 'borrador';
    case Confirmada = 'confirmada';
    case Anulada = 'anulada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Confirmada => 'Confirmada',
            self::Anulada => 'Anulada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Borrador => 'warning',
            self::Confirmada => 'success',
            self::Anulada => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Borrador => 'heroicon-o-pencil-square',
            self::Confirmada => 'heroicon-o-check-circle',
            self::Anulada => 'heroicon-o-x-circle',
        };
    }

    /**
     * ¿Se puede editar una compra en este estado?
     */
    public function isEditable(): bool
    {
        return $this === self::Borrador;
    }

    /**
     * ¿Se puede confirmar desde este estado?
     */
    public function canConfirm(): bool
    {
        return $this === self::Borrador;
    }

    /**
     * ¿Se puede anular desde este estado?
     */
    public function canCancel(): bool
    {
        return $this !== self::Anulada;
    }
}

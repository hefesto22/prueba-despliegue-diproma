<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Condición del producto.
 * Impacto fiscal: bienes usados están exentos de ISV
 * (Art. 15 inciso e, Decreto 194-2002).
 */
enum ProductCondition: string implements HasLabel
{
    case New = 'new';
    case Used = 'used';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Nuevo',
            self::Used => 'Usado',
        };
    }

    /**
     * Determinar si esta condición está exenta de ISV.
     */
    public function isExemptFromIsv(): bool
    {
        return $this === self::Used;
    }
}

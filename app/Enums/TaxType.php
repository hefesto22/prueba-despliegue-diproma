<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Clasificación fiscal del producto según la SAR.
 *
 * - gravado_15: ISV estándar del 15%
 * - exento: Sin ISV (bienes usados, Art. 15 inciso e, Decreto 194-2002)
 *
 * Nota: La tasa del 18% aplica solo a bebidas alcohólicas y tabaco,
 * no relevante para electrónicos.
 */
enum TaxType: string implements HasLabel
{
    case Gravado15 = 'gravado_15';
    case Exento = 'exento';

    public function getLabel(): string
    {
        return match ($this) {
            self::Gravado15 => 'Gravado 15%',
            self::Exento => 'Exento',
        };
    }

    /**
     * Obtener la tasa decimal para cálculos.
     * Centralizado en config/tax.php para facilitar cambios de SAR.
     */
    public function rate(): float
    {
        return match ($this) {
            self::Gravado15 => (float) config('tax.standard_rate', 0.15),
            self::Exento => 0.0,
        };
    }

    /**
     * Obtener el porcentaje entero para presentación.
     */
    public function percentage(): int
    {
        return match ($this) {
            self::Gravado15 => (int) config('tax.standard_percentage', 15),
            self::Exento => 0,
        };
    }

    /**
     * Multiplicador para convertir base ↔ precio con ISV.
     */
    public function multiplier(): float
    {
        return match ($this) {
            self::Gravado15 => (float) config('tax.multiplier', 1.15),
            self::Exento => 1.0,
        };
    }
}

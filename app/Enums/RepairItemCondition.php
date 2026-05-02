<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Condición de una pieza externa en una Reparación.
 *
 * Solo aplica a items con `source = PiezaExterna`.
 * Determina el `tax_type` automáticamente:
 *   - Nueva → Gravado 15%  (precio incluye ISV — consistente con SaleItem)
 *   - Usada → Exento       (Art. 15 inciso e, Decreto 194-2002)
 *
 * Para piezas de inventario propio (`source = PiezaInventario`) NO se usa
 * este enum: el tax_type viene del Product del catálogo.
 */
enum RepairItemCondition: string implements HasLabel
{
    case Nueva = 'nueva';
    case Usada = 'usada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Nueva => 'Nueva',
            self::Usada => 'Usada',
        };
    }

    public function toTaxType(): TaxType
    {
        return match ($this) {
            self::Nueva => TaxType::Gravado15,
            self::Usada => TaxType::Exento,
        };
    }

    public function isExempt(): bool
    {
        return $this === self::Usada;
    }
}

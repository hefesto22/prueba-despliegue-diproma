<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Tipo de descuento aplicable a una venta.
 */
enum DiscountType: string implements HasLabel
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Percentage => 'Porcentaje (%)',
            self::Fixed => 'Monto Fijo (L)',
        };
    }

    /**
     * Calcular el monto del descuento a partir del valor ingresado y el total bruto.
     *
     * @param float $value    Valor ingresado (ej: 10 para 10%, o 200 para L200)
     * @param float $grossTotal  Total bruto antes de descuento
     * @return float Monto del descuento en Lempiras
     */
    public function calculateAmount(float $value, float $grossTotal): float
    {
        return match ($this) {
            self::Percentage => round($grossTotal * ($value / 100), 2),
            self::Fixed => round(min($value, $grossTotal), 2), // Nunca mayor al total
        };
    }
}

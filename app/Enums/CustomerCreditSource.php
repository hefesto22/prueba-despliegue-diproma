<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Origen de un crédito a favor del cliente.
 *
 * Diseño extensible: hoy solo aparece `RepairAdvance` (anticipos de
 * reparación que el cliente dejó y la reparación se rechazó/anuló y
 * decidió convertir en crédito en vez de devolución).
 *
 * Futuros: `OverpaidInvoice`, `ManualAdjustment`, `LoyaltyProgram`, etc.
 *
 * Este enum NO sustituye a `CreditNote` (Nota de Crédito SAR tipo 03):
 *   - CreditNote: documento fiscal que acredita una factura ya emitida.
 *   - CustomerCredit: saldo a favor que existe ANTES de cualquier factura
 *     (en este caso: anticipo cobrado pero la reparación nunca se entregó).
 */
enum CustomerCreditSource: string implements HasLabel, HasColor, HasIcon
{
    case RepairAdvance = 'repair_advance';

    public function getLabel(): string
    {
        return match ($this) {
            self::RepairAdvance => 'Anticipo de reparación',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::RepairAdvance => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::RepairAdvance => 'heroicon-o-wrench-screwdriver',
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Flujo de estados de una compra:
 *
 * borrador → confirmada (el documento entra al Libro de Compras; contado = Pagada)
 * borrador → anulada (descarta el borrador, sin efectos)
 * confirmada → anulada (sale del Libro de Compras; si es una compra heredada
 *                       con productos, además revierte su stock — NO el costo)
 *
 * Desde la Fase 1 (2026-07-25) ninguna transición mete stock: el inventario
 * entra por la ficha del producto. Ver PurchaseService.
 */
enum PurchaseStatus: string implements HasColor, HasIcon, HasLabel
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

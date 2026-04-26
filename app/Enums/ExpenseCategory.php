<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Categoría de gasto menor de caja chica.
 *
 * Solo aplica cuando `CashMovementType` es `expense`. Permite agrupar gastos
 * para reportes mensuales y detectar anomalías (ej. "combustible creció 40%").
 *
 * Por qué enum fijo y no tabla de categorías:
 *   - El set de categorías cambia con baja frecuencia (una vez al año).
 *   - Un enum fijo elimina la complejidad de CRUD + seed + migración por
 *     cada categoría nueva.
 *   - Si el negocio demuestra necesitar categorías dinámicas (ej: por proyecto),
 *     se migra a tabla — YAGNI hasta entonces.
 *
 * Extender el set: agregar un caso acá, agregar label/color/icon, listo.
 */
enum ExpenseCategory: string implements HasLabel, HasColor, HasIcon
{
    case Combustible = 'combustible';
    case Mensajeria = 'mensajeria';
    case Papeleria = 'papeleria';
    case Mantenimiento = 'mantenimiento';
    case Servicios = 'servicios';
    case Otros = 'otros';

    public function getLabel(): string
    {
        return match ($this) {
            self::Combustible => 'Combustible',
            self::Mensajeria => 'Mensajería',
            self::Papeleria => 'Papelería',
            self::Mantenimiento => 'Mantenimiento',
            self::Servicios => 'Servicios básicos',
            self::Otros => 'Otros',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Combustible => 'warning',
            self::Mensajeria => 'info',
            self::Papeleria => 'gray',
            self::Mantenimiento => 'danger',
            self::Servicios => 'primary',
            self::Otros => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Combustible => 'heroicon-o-fire',
            self::Mensajeria => 'heroicon-o-paper-airplane',
            self::Papeleria => 'heroicon-o-document',
            self::Mantenimiento => 'heroicon-o-wrench-screwdriver',
            self::Servicios => 'heroicon-o-bolt',
            self::Otros => 'heroicon-o-ellipsis-horizontal-circle',
        };
    }
}

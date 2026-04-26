<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Razones SAR válidas para emitir una Nota de Crédito.
 *
 * Se decide aquí si la NC devuelve stock al kardex:
 *   - devolucion_fisica → SÍ retorna a inventario (MovementType::EntradaNotaCredito).
 *   - resto             → NO toca inventario (solo ajuste fiscal/monetario).
 *
 * La bandera `returnsToInventory()` es derivada del enum — no es un campo
 * separado en credit_notes para evitar que dos fuentes de verdad puedan
 * divergir.
 *
 * Cuando la razón ≠ devolucion_fisica, el campo `reason_notes` de la NC
 * pasa a ser obligatorio (lo valida el FormRequest / Service).
 */
enum CreditNoteReason: string implements HasLabel, HasColor, HasIcon
{
    case DevolucionFisica     = 'devolucion_fisica';
    case DescuentoPostVenta   = 'descuento_post_venta';
    case CorreccionError      = 'correccion_error';
    case AjusteComercial      = 'ajuste_comercial';

    public function getLabel(): string
    {
        return match ($this) {
            self::DevolucionFisica   => 'Devolución física',
            self::DescuentoPostVenta => 'Descuento post-venta',
            self::CorreccionError    => 'Corrección de error',
            self::AjusteComercial    => 'Ajuste comercial',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DevolucionFisica   => 'warning',
            self::DescuentoPostVenta => 'info',
            self::CorreccionError    => 'danger',
            self::AjusteComercial    => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::DevolucionFisica   => 'heroicon-o-arrow-uturn-left',
            self::DescuentoPostVenta => 'heroicon-o-banknotes',
            self::CorreccionError    => 'heroicon-o-exclamation-triangle',
            self::AjusteComercial    => 'heroicon-o-adjustments-horizontal',
        };
    }

    /**
     * ¿Esta razón implica devolver stock al kardex?
     *
     * Única fuente de verdad — el servicio de NC la consulta para decidir
     * si registra un InventoryMovement::EntradaNotaCredito.
     */
    public function returnsToInventory(): bool
    {
        return $this === self::DevolucionFisica;
    }

    /**
     * ¿Esta razón exige un comentario explicativo en `reason_notes`?
     *
     * Una devolución física se autodocumenta (el producto vuelve). Las
     * demás razones requieren explicación fiscal/comercial explícita.
     */
    public function requiresNotes(): bool
    {
        return $this !== self::DevolucionFisica;
    }
}

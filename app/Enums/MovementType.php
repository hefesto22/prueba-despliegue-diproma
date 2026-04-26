<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Tipos de movimiento de inventario.
 *
 * Automáticos (creados por el sistema):
 * - entrada_compra: al confirmar una compra
 * - salida_anulacion_compra: al anular una compra confirmada
 * - salida_venta: al procesar una venta
 * - entrada_anulacion_venta: al anular una venta completada
 * - entrada_nota_credito: al emitir una NC con razón = devolucion_fisica
 * - salida_anulacion_nota_credito: al anular una NC que había registrado
 *   una entrada_nota_credito (cierra el ciclo para que el kardex sea
 *   simétrico con el flujo de anulación de compra/venta).
 *
 * Manuales (creados por el usuario):
 * - ajuste_entrada: corrección positiva (conteo físico, devolución)
 * - ajuste_salida: corrección negativa (merma, daño, robo)
 */
enum MovementType: string implements HasLabel, HasColor, HasIcon
{
    // Compras
    case EntradaCompra = 'entrada_compra';
    case SalidaAnulacionCompra = 'salida_anulacion_compra';

    // Ventas
    case SalidaVenta = 'salida_venta';
    case EntradaAnulacionVenta = 'entrada_anulacion_venta';

    // Notas de crédito
    case EntradaNotaCredito = 'entrada_nota_credito';
    case SalidaAnulacionNotaCredito = 'salida_anulacion_nota_credito';

    // Ajustes manuales
    case AjusteEntrada = 'ajuste_entrada';
    case AjusteSalida = 'ajuste_salida';

    public function getLabel(): string
    {
        return match ($this) {
            self::EntradaCompra => 'Entrada (Compra)',
            self::SalidaAnulacionCompra => 'Salida (Anulación Compra)',
            self::SalidaVenta => 'Salida (Venta)',
            self::EntradaAnulacionVenta => 'Entrada (Anulación Venta)',
            self::EntradaNotaCredito => 'Entrada (Nota de Crédito)',
            self::SalidaAnulacionNotaCredito => 'Salida (Anulación Nota de Crédito)',
            self::AjusteEntrada => 'Ajuste (+)',
            self::AjusteSalida => 'Ajuste (−)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EntradaCompra, self::EntradaAnulacionVenta, self::EntradaNotaCredito, self::AjusteEntrada => 'success',
            self::SalidaAnulacionCompra, self::SalidaVenta, self::SalidaAnulacionNotaCredito, self::AjusteSalida => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::EntradaCompra => 'heroicon-o-arrow-down-tray',
            self::SalidaAnulacionCompra => 'heroicon-o-arrow-up-tray',
            self::SalidaVenta => 'heroicon-o-shopping-bag',
            self::EntradaAnulacionVenta => 'heroicon-o-arrow-uturn-left',
            self::EntradaNotaCredito => 'heroicon-o-receipt-refund',
            self::SalidaAnulacionNotaCredito => 'heroicon-o-arrow-uturn-right',
            self::AjusteEntrada => 'heroicon-o-plus-circle',
            self::AjusteSalida => 'heroicon-o-minus-circle',
        };
    }

    /**
     * ¿Este tipo suma stock?
     */
    public function isEntry(): bool
    {
        return in_array($this, [
            self::EntradaCompra,
            self::EntradaAnulacionVenta,
            self::EntradaNotaCredito,
            self::AjusteEntrada,
        ]);
    }

    /**
     * ¿Este tipo resta stock?
     */
    public function isExit(): bool
    {
        return in_array($this, [
            self::SalidaAnulacionCompra,
            self::SalidaVenta,
            self::SalidaAnulacionNotaCredito,
            self::AjusteSalida,
        ]);
    }

    /**
     * ¿Es un movimiento generado automáticamente por el sistema?
     */
    public function isAutomatic(): bool
    {
        return in_array($this, [
            self::EntradaCompra,
            self::SalidaAnulacionCompra,
            self::SalidaVenta,
            self::EntradaAnulacionVenta,
            self::EntradaNotaCredito,
            self::SalidaAnulacionNotaCredito,
        ]);
    }
}

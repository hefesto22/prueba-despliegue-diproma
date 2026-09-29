<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * ¿Qué se compró? — clasifica cada compra para la Utilidad Neta.
 *
 * Desde el rediseño "todo en Compras" (2026-09-28) Compras es el único lugar
 * donde se registran salidas de dinero a terceros, así que hay que distinguir:
 *
 *   - Mercadería: lo que se compra para vender o usar en reparaciones. NO
 *     es gasto del mes: su costo entra a la Utilidad por el costo de lo
 *     vendido (Kardex), cuando se vende.
 *   - Gasto: gasto operativo (combustible, papelería, servicios, taxi…).
 *     Resta de la Utilidad Neta del mes en que se registra y lleva
 *     categoría (ExpenseCategory) para el Reporte Mensual de Gastos.
 *
 * El tipo es independiente del documento: un gasto puede venir con Factura
 * (entra al Libro de Compras con su ISV) o con Recibo Interno (sin CAI).
 */
enum PurchaseKind: string implements HasColor, HasIcon, HasLabel
{
    case Mercaderia = 'mercaderia';
    case Gasto = 'gasto';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mercaderia => 'Mercadería',
            self::Gasto => 'Gasto operativo',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Mercaderia => 'primary',
            self::Gasto => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Mercaderia => 'heroicon-o-cube',
            self::Gasto => 'heroicon-o-banknotes',
        };
    }

    /**
     * Documento que suele respaldar este tipo de compra. El formulario lo
     * preselecciona para ahorrar clics; el operador puede cambiarlo.
     *
     * Los gastos del día (taxi, mercado, mensajería) casi nunca traen CAI;
     * la mercadería se compra a distribuidores formales con factura.
     */
    public function suggestedDocumentType(): SupplierDocumentType
    {
        return match ($this) {
            self::Mercaderia => SupplierDocumentType::Factura,
            self::Gasto => SupplierDocumentType::ReciboInterno,
        };
    }

    /**
     * Forma de pago habitual, o null si no hay una que predomine. Un gasto
     * del día se paga del cajón; la mercadería varía (transferencia, cheque…)
     * y es mejor que el operador la elija.
     */
    public function suggestedPaymentMethod(): ?PaymentMethod
    {
        return match ($this) {
            self::Mercaderia => null,
            self::Gasto => PaymentMethod::Efectivo,
        };
    }

    /**
     * Normaliza el state de un campo de formulario (string o enum) a enum.
     */
    public static function fromState(mixed $state): ?self
    {
        return match (true) {
            $state instanceof self => $state,
            is_string($state) && $state !== '' => self::tryFrom($state),
            default => null,
        };
    }
}

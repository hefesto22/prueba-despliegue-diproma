<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Origen / tipo de un RepairItem.
 *
 * Reemplaza el campo único "Mano obra" del sistema viejo. Cada línea de
 * cotización ahora tiene origen explícito, lo que permite tipificar
 * correctamente cada concepto en la factura CAI:
 *
 *   - HonorariosReparacion / HonorariosMantenimiento → exentos de ISV.
 *     Respaldo: el contador los registra como honorarios profesionales
 *     por servicio prestado (no como mano de obra de servicio comercial),
 *     lo que los pone bajo Art. 15 inciso e del Decreto 194-2002.
 *
 *   - PiezaExterna → comprada puntualmente para esta reparación.
 *     El tax_type se deriva de `RepairItemCondition`:
 *       Nueva → Gravado 15% (precio ya incluye ISV)
 *       Usada → Exento
 *
 *   - PiezaInventario → sale del stock propio de Diproma.
 *     El tax_type viene del `Product` del catálogo (consistente con SaleItem).
 *     Este source es el ÚNICO que afecta kardex/stock al entregar.
 */
enum RepairItemSource: string implements HasLabel, HasColor, HasIcon
{
    case HonorariosReparacion = 'honorarios_reparacion';
    case HonorariosMantenimiento = 'honorarios_mantenimiento';
    case PiezaExterna = 'pieza_externa';
    case PiezaInventario = 'pieza_inventario';

    public function getLabel(): string
    {
        return match ($this) {
            self::HonorariosReparacion => 'Honorarios por reparación',
            self::HonorariosMantenimiento => 'Honorarios por mantenimiento',
            self::PiezaExterna => 'Pieza (compra externa)',
            self::PiezaInventario => 'Pieza (inventario propio)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::HonorariosReparacion, self::HonorariosMantenimiento => 'info',
            self::PiezaExterna => 'warning',
            self::PiezaInventario => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::HonorariosReparacion => 'heroicon-o-wrench',
            self::HonorariosMantenimiento => 'heroicon-o-cog-6-tooth',
            self::PiezaExterna => 'heroicon-o-shopping-bag',
            self::PiezaInventario => 'heroicon-o-cube',
        };
    }

    /** ¿El concepto es un servicio (honorarios)? */
    public function isService(): bool
    {
        return match ($this) {
            self::HonorariosReparacion, self::HonorariosMantenimiento => true,
            default => false,
        };
    }

    /** ¿La línea requiere `RepairItemCondition` (nueva/usada)? */
    public function requiresCondition(): bool
    {
        return $this === self::PiezaExterna;
    }

    /** ¿La línea exige product_id obligatorio? */
    public function requiresProduct(): bool
    {
        return $this === self::PiezaInventario;
    }

    /** ¿La línea afecta inventario al entregar? */
    public function affectsInventory(): bool
    {
        return $this === self::PiezaInventario;
    }

    /**
     * Resolver el `TaxType` de la línea.
     *
     * Para `PiezaInventario` retorna null porque el caller debe leerlo del
     * Product del catálogo (no podemos inferirlo desde el enum solo).
     * Fail-fast en caller si llega null y se intenta calcular total.
     */
    public function resolveTaxType(?RepairItemCondition $condition = null): ?TaxType
    {
        return match ($this) {
            self::HonorariosReparacion, self::HonorariosMantenimiento => TaxType::Exento,
            self::PiezaExterna => $condition?->toTaxType()
                ?? throw new \InvalidArgumentException(
                    'PiezaExterna requiere RepairItemCondition (nueva o usada).'
                ),
            self::PiezaInventario => null, // caller debe leer Product->tax_type
        };
    }

    /** Sources que aparecen en el dropdown del form. */
    public static function selectable(): array
    {
        return [
            self::HonorariosReparacion,
            self::HonorariosMantenimiento,
            self::PiezaExterna,
            self::PiezaInventario,
        ];
    }
}

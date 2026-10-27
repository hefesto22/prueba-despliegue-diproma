<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\TaxType;

/**
 * Línea libre de una factura especial: un concepto que no sale del catálogo
 * (un mantenimiento puntual, un servicio sin ficha de producto — casos fuera
 * del manejo normal del sistema).
 *
 * Mismas convenciones que el resto de líneas de venta del proyecto:
 *   - `unitPrice` INCLUYE el ISV cuando la línea es gravada (lo que paga el
 *     cliente). SaleTaxCalculator hace el back-out base/ISV al persistir.
 *   - `unitCost` es NETO (sin ISV), igual que el costo promedio del catálogo
 *     y el costo de piezas externas de Reparaciones. Opcional: solo alimenta
 *     la ganancia bruta del Escritorio (COALESCE con sale_items.unit_cost).
 *   - `description` en MAYÚSCULAS, igual que el nombre de cliente y el
 *     detalle de servicios del POS, para que la factura se vea uniforme.
 *
 * Inmutable y validada en el constructor (fail-fast): una línea inválida
 * nunca llega a la transacción de la venta.
 */
final class FreeSaleLine
{
    /** Tope de la columna sale_items.description (string 300). */
    public const MAX_DESCRIPTION_LENGTH = 300;

    public readonly string $description;

    public readonly float $unitPrice;

    public readonly ?float $unitCost;

    /**
     * @throws \InvalidArgumentException Si la descripción está vacía o es muy
     *                                   larga, la cantidad es < 1, el precio
     *                                   es <= 0 o el costo es negativo.
     */
    public function __construct(
        string $description,
        public readonly int $quantity,
        float $unitPrice,
        public readonly TaxType $taxType,
        ?float $unitCost = null,
    ) {
        $description = mb_strtoupper(trim($description), 'UTF-8');

        if ($description === '') {
            throw new \InvalidArgumentException('Cada línea de la factura necesita una descripción.');
        }

        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'La descripción no puede pasar de %d caracteres (tiene %d): "%s".',
                self::MAX_DESCRIPTION_LENGTH,
                mb_strlen($description),
                mb_substr($description, 0, 40),
            ));
        }

        if ($quantity < 1) {
            throw new \InvalidArgumentException(
                "La cantidad debe ser al menos 1. Recibido {$quantity} en \"{$description}\"."
            );
        }

        if ($unitPrice <= 0) {
            throw new \InvalidArgumentException(
                "El precio debe ser mayor a cero. Recibido {$unitPrice} en \"{$description}\"."
            );
        }

        if ($unitCost !== null && $unitCost < 0) {
            throw new \InvalidArgumentException(
                "El costo no puede ser negativo. Recibido {$unitCost} en \"{$description}\"."
            );
        }

        $this->description = $description;
        $this->unitPrice = round($unitPrice, 2);
        $this->unitCost = $unitCost !== null ? round($unitCost, 2) : null;
    }
}

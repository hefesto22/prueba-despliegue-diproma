<?php

declare(strict_types=1);

namespace App\Services\Purchases\Exceptions;

use RuntimeException;

/**
 * Se intenta confirmar un borrador capturado con el modelo anterior de Compras
 * (con líneas de producto).
 *
 * Desde la Fase 1 del rediseño Compras + Producto-lote, confirmar ya no mete
 * stock ni recalcula costo. Si dejáramos confirmar ese borrador, sus productos
 * quedarían en `purchase_items` como si hubieran entrado al inventario sin que
 * una sola unidad llegue al stock ni al Kardex — un faltante silencioso que
 * solo aparecería al contar físico.
 *
 * La salida correcta es anularlo y volver a capturarlo con el flujo nuevo:
 * el documento en Compras y cada equipo como ficha en Productos.
 */
class CompraConProductosHeredadaException extends RuntimeException
{
    public function __construct(
        public readonly string $purchaseNumber,
    ) {
        parent::__construct(
            "La compra {$purchaseNumber} se capturó con el formato anterior (con productos) y ya no puede confirmarse: "
            .'confirmarla no metería esas unidades al inventario. Anúlela y regístrela de nuevo — '
            .'el documento aquí en Compras y los equipos como fichas en Productos.'
        );
    }
}

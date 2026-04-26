<?php

namespace App\Services\CreditNotes\Exceptions;

/**
 * No se puede anular una NC con razón de devolución física porque el stock
 * actual del producto es insuficiente para revertir la entrada que registró.
 *
 * Caso de uso real: cliente devolvió 3 unidades → se emitió NC con
 * DevolucionFisica → stock subió de 0 a 3 → el negocio revendió las 3
 * unidades a otro cliente → stock de nuevo en 0 → ahora intento anular la NC
 * original (el primer cliente dice "no lo devolví después de todo").
 *
 * Si procediéramos silenciosamente el stock iría a -3 — inventario fantasma
 * negativo. Bloqueamos con esta excepción para forzar al usuario a resolver
 * el caso con un ajuste manual explícito o una nota de débito, según el
 * criterio contable correcto para su escenario.
 */
class StockInsuficienteParaAnularNCException extends CreditNoteException
{
    public function __construct(
        public readonly int $creditNoteId,
        public readonly string $creditNoteNumber,
        public readonly int $productId,
        public readonly string $productName,
        public readonly int $requerido,
        public readonly int $disponible,
    ) {
        parent::__construct(
            "No se puede anular la nota de crédito {$creditNoteNumber}: el producto "
            . "«{$productName}» (#{$productId}) tiene stock {$disponible} pero se "
            . "requiere revertir {$requerido} unidades. Probablemente la mercadería "
            . 'devuelta ya fue revendida. Ajuste el inventario manualmente o emita '
            . 'una nota de débito antes de intentar anular nuevamente.'
        );
    }
}

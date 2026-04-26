<?php

namespace App\Services\FiscalBooks;

use Illuminate\Support\Collection;

/**
 * DTO que agrupa el resultado completo del Libro de Compras de un período:
 *   - La colección ordenada de entradas (detalle)
 *   - El resumen calculado (totales por tipo de documento)
 *
 * Se construye una sola vez en PurchaseBookService y se pasa al Export. Evita
 * recalcular totales dos veces (una para la hoja detalle, otra para la hoja
 * resumen) que sería un antipatrón de performance con muchas compras.
 *
 * Espejo simétrico de `SalesBook` — misma forma para que el código de
 * exportación pueda compartir helpers y tests pueda asumir contratos idénticos.
 */
final class PurchaseBook
{
    /**
     * @param  Collection<int, PurchaseBookEntry>  $entries
     */
    public function __construct(
        public readonly Collection $entries,
        public readonly PurchaseBookSummary $summary,
    ) {}
}

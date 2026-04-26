<?php

namespace App\Services\FiscalBooks;

use Illuminate\Support\Collection;

/**
 * DTO que agrupa el resultado completo del Libro de Ventas de un período:
 *   - La colección ordenada de entradas (detalle)
 *   - El resumen calculado (totales)
 *
 * Se construye una sola vez en SalesBookService y se pasa al Export. Evita
 * recalcular totales dos veces (una para la hoja detalle, otra para la hoja
 * resumen) que sería un antipatrón de performance con muchos documentos.
 */
final class SalesBook
{
    /**
     * @param  Collection<int, SalesBookEntry>  $entries
     */
    public function __construct(
        public readonly Collection $entries,
        public readonly SalesBookSummary $summary,
    ) {}
}

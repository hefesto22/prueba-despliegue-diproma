<?php

namespace App\Exports\PurchaseBook;

use App\Services\FiscalBooks\PurchaseBook;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Orquestador del Libro de Compras SAR.
 *
 * Genera un archivo XLSX con dos hojas:
 *   1. "Resumen YYYY-MM"  — totales del período para declaración ISV-353 (crédito fiscal)
 *   2. "Detalle YYYY-MM"  — línea por línea ordenada cronológicamente
 *
 * La hoja Resumen va primero para que el contador vea de inmediato los
 * totales a declarar al abrir el archivo. El detalle queda como soporte.
 *
 * El DTO PurchaseBook se construye una sola vez en PurchaseBookService y se
 * reutiliza para ambas hojas — evita doble query y doble cálculo.
 *
 * Espejo simétrico de SalesBookExport — misma forma para que el código del
 * FiscalPeriodResource pueda tratar ambos libros homogéneamente.
 */
class PurchaseBookExport implements WithMultipleSheets
{
    public function __construct(
        private readonly PurchaseBook $book,
    ) {}

    public function sheets(): array
    {
        return [
            new PurchaseBookSummarySheet($this->book),
            new PurchaseBookDetailSheet($this->book),
        ];
    }

    /**
     * Nombre sugerido para la descarga: "Libro-Compras-2026-04.xlsx"
     */
    public function fileName(): string
    {
        return 'Libro-Compras-' . $this->book->summary->periodSlug() . '.xlsx';
    }
}

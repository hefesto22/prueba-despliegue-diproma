<?php

namespace App\Exports\SalesBook;

use App\Services\FiscalBooks\SalesBook;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Orquestador del Libro de Ventas SAR.
 *
 * Genera un archivo XLSX con dos hojas:
 *   1. "Resumen YYYY-MM"  — totales del período para declaración ISV-353
 *   2. "Detalle YYYY-MM"  — línea por línea ordenada cronológicamente
 *
 * La hoja Resumen va primero para que el contador vea de inmediato los
 * totales a declarar al abrir el archivo. El detalle queda como soporte.
 *
 * El DTO SalesBook se construye una sola vez en SalesBookService y se
 * reutiliza para ambas hojas — evita doble query y doble cálculo.
 */
class SalesBookExport implements WithMultipleSheets
{
    public function __construct(
        private readonly SalesBook $book,
    ) {}

    public function sheets(): array
    {
        return [
            new SalesBookSummarySheet($this->book),
            new SalesBookDetailSheet($this->book),
        ];
    }

    /**
     * Nombre sugerido para la descarga: "Libro-Ventas-2026-04.xlsx"
     */
    public function fileName(): string
    {
        return 'Libro-Ventas-' . $this->book->summary->periodSlug() . '.xlsx';
    }
}

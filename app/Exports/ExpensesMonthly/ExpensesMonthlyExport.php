<?php

declare(strict_types=1);

namespace App\Exports\ExpensesMonthly;

use App\Services\Expenses\ExpensesMonthlyReport;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Orquestador del Reporte Mensual de Gastos.
 *
 * Genera un archivo XLSX con dos hojas:
 *   1. "Resumen YYYY-MM"  — KPIs del período (totales, deducibles, alertas) y
 *                            desgloses por categoría / método de pago / sucursal.
 *   2. "Detalle YYYY-MM"  — línea por línea ordenada cronológicamente, con
 *                            todas las columnas operativas y fiscales.
 *
 * El Resumen va primero porque es lo que el contador necesita ver al abrir
 * el archivo: el `creditoFiscalDeducible` para el Formulario 201 y la
 * cantidad de `deduciblesIncompletos` que tiene que revisar antes de declarar.
 *
 * Mismo patrón que `PurchaseBookExport` y `SalesBookExport` — el DTO se
 * construye una sola vez en `ExpensesMonthlyReportService` y se reutiliza
 * para ambas hojas, evitando doble query y doble cálculo.
 *
 * Diferencia con los libros fiscales:
 *   - Este NO es un libro SAR (no se entrega al fisco). Es un reporte de
 *     gestión interno que el contador usa para revisar la calidad de los
 *     gastos antes del 10 de cada mes (deadline de pago de ISV).
 */
class ExpensesMonthlyExport implements WithMultipleSheets
{
    public function __construct(
        private readonly ExpensesMonthlyReport $report,
    ) {}

    public function sheets(): array
    {
        return [
            new ExpensesMonthlySummarySheet($this->report),
            new ExpensesMonthlyDetailSheet($this->report),
        ];
    }

    /**
     * Nombre sugerido para la descarga: "Reporte-Gastos-2026-04.xlsx"
     */
    public function fileName(): string
    {
        return 'Reporte-Gastos-' . $this->report->summary->periodSlug() . '.xlsx';
    }
}

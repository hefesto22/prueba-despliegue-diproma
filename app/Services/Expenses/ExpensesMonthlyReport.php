<?php

declare(strict_types=1);

namespace App\Services\Expenses;

use Illuminate\Support\Collection;

/**
 * DTO que agrupa el resultado completo del Reporte Mensual de Gastos:
 *   - La colección ordenada de entradas (detalle)
 *   - El resumen calculado (totales, KPIs, desgloses por categoría)
 *
 * Se construye una sola vez en `ExpensesMonthlyReportService::build()` y se
 * pasa tanto a la Page (que muestra KPIs visuales) como al Export (que genera
 * el Excel de 2 hojas). Evita doble query y doble cálculo.
 *
 * Misma forma que `PurchaseBook` / `SalesBook` — patrón unificado de DTOs de
 * reportes en el proyecto.
 */
final class ExpensesMonthlyReport
{
    /**
     * @param  Collection<int, ExpensesMonthlyReportEntry>  $entries
     */
    public function __construct(
        public readonly Collection $entries,
        public readonly ExpensesMonthlyReportSummary $summary,
    ) {}
}

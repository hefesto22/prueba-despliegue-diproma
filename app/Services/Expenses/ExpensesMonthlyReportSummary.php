<?php

declare(strict_types=1);

namespace App\Services\Expenses;

/**
 * Resumen del Reporte Mensual de Gastos.
 *
 * Value object inmutable con los KPIs que el contador y el admin usan para
 * cuadrar el cierre del mes:
 *
 *   - Total gastos del período (cantidad + monto)
 *   - Total deducible de ISV (monto + crédito fiscal calculado por suma de
 *     isv_amount donde is_isv_deductible = true)
 *   - Alerta de deducibles incompletos (deducibles sin RTN/factura/CAI)
 *   - Desglose por categoría, sucursal, método de pago
 *
 * Diferencia con `PurchaseBookSummary`:
 *   - PurchaseBook se cuadra contra Formulario ISV-353 — totales SAR.
 *   - ExpensesReport es un reporte de gestión interno: no es un libro fiscal,
 *     sirve para que el contador revise calidad de datos antes de declarar
 *     y para archivo del cierre mensual.
 *
 * El `creditoFiscalDeducible` es la magnitud que el contador suma a su
 * Formulario 201 (declaración ISV mensual) en el campo "Crédito fiscal por
 * compras y gastos". Si hay gastos deducibles incompletos, ese monto está
 * en riesgo: SAR puede rechazarlo en auditoría.
 */
final class ExpensesMonthlyReportSummary
{
    /**
     * @param  array<string, array{label: string, count: int, total: float}>  $byCategory
     *         Agrupado por value de ExpenseCategory. Label resuelto al construir.
     * @param  array<int, array{name: string, count: int, total: float}>  $byEstablishment
     *         Agrupado por establishment_id. Name resuelto al construir.
     * @param  array<string, array{label: string, count: int, total: float}>  $byPaymentMethod
     *         Agrupado por value de PaymentMethod. Label resuelto al construir.
     */
    public function __construct(
        public readonly int $year,
        public readonly int $month,

        // Totales globales del período
        public readonly int $gastosCount,
        public readonly float $gastosTotal,

        // Deducibles de ISV (crédito fiscal)
        public readonly int $deduciblesCount,
        public readonly float $deduciblesTotal,
        public readonly float $creditoFiscalDeducible,
        public readonly int $deduciblesIncompletosCount,

        // No deducibles (gasto puro)
        public readonly int $noDeduciblesCount,
        public readonly float $noDeduciblesTotal,

        // Impacto en caja (afecta vs no afecta saldo físico)
        public readonly int $cashCount,
        public readonly float $cashTotal,
        public readonly int $nonCashCount,
        public readonly float $nonCashTotal,

        // Desgloses
        public readonly array $byCategory,
        public readonly array $byEstablishment,
        public readonly array $byPaymentMethod,
    ) {}

    /**
     * Etiqueta "Abril 2026" para títulos y nombres de archivo.
     */
    public function periodLabel(): string
    {
        $meses = [
            1  => 'Enero',     2  => 'Febrero',   3  => 'Marzo',     4  => 'Abril',
            5  => 'Mayo',      6  => 'Junio',     7  => 'Julio',     8  => 'Agosto',
            9  => 'Septiembre',10 => 'Octubre',   11 => 'Noviembre', 12 => 'Diciembre',
        ];

        return ($meses[$this->month] ?? (string) $this->month) . ' ' . $this->year;
    }

    /**
     * Sufijo "2026-04" para slugs / nombres de archivo.
     */
    public function periodSlug(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    /**
     * ¿Hay alertas de calidad de datos que el contador debe revisar antes
     * de tomar el `creditoFiscalDeducible` para la declaración?
     */
    public function hasIncompleteWarnings(): bool
    {
        return $this->deduciblesIncompletosCount > 0;
    }
}

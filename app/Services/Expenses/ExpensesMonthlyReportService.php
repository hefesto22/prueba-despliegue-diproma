<?php

declare(strict_types=1);

namespace App\Services\Expenses;

use App\Models\Expense;
use Illuminate\Support\Collection;

/**
 * Service que construye el DTO `ExpensesMonthlyReport` para un período.
 *
 * Una sola query a `expenses` (con eager load de establishment + user) cubre
 * tanto el detalle como el resumen — los totales se computan iterando la
 * colección en PHP en lugar de disparar 4-5 queries de aggregation por
 * categoría/método/sucursal/deducibilidad.
 *
 * POR QUÉ ITERACIÓN EN PHP Y NO SQL AGGREGATION:
 *   - Volúmenes esperados: 50-300 gastos/mes en single-tenant. Iterar 300
 *     filas en PHP es trivial (ms). Disparar 5 queries de COUNT/SUM agregadas
 *     genera más overhead de network/parse que ahorro de cómputo.
 *   - Cohesión: la regla de "deducible incompleto" (deducible sin RTN/factura/CAI)
 *     vive en `ExpensesMonthlyReportEntry::fromExpense()`. Si la calculáramos
 *     en SQL tendríamos que duplicar la lógica en dos lugares.
 *   - Cuando los volúmenes superen ~5000 gastos/mes (improbable a corto plazo
 *     en single-tenant), se cambia a aggregation queries sin tocar el
 *     contrato del DTO. Hoy el patrón es YAGNI-correcto.
 *
 * INDICES UTILIZADOS:
 *   - `expenses_estab_date_idx (establishment_id, expense_date)` cuando hay
 *     filtro de sucursal — es el caso típico.
 *   - Sin sucursal: `whereYear/whereMonth` no usa índice de columna por sí
 *     mismo, pero dado el volumen (single tenant, 1-2 sucursales), un seq
 *     scan filtrado de ~3000-5000 filas/año es <50ms en PostgreSQL. Si en el
 *     futuro escala mal, se reemplaza por `whereBetween` con expense_date
 *     calculado para usar el índice.
 */
class ExpensesMonthlyReportService
{
    /**
     * Construye el reporte completo para un período.
     *
     * @param  int|null  $establishmentId  Filtro opcional. Null = todas las sucursales.
     */
    public function build(int $year, int $month, ?int $establishmentId = null): ExpensesMonthlyReport
    {
        $expenses = Expense::query()
            ->with([
                'establishment:id,name',
                'user:id,name',
            ])
            ->forMonth($year, $month)
            ->when(
                $establishmentId !== null,
                fn ($q) => $q->where('establishment_id', $establishmentId),
            )
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get();

        $entries = $expenses->map(
            fn (Expense $e) => ExpensesMonthlyReportEntry::fromExpense($e),
        )->values();

        $summary = $this->buildSummary($year, $month, $entries);

        return new ExpensesMonthlyReport(
            entries: $entries,
            summary: $summary,
        );
    }

    /**
     * Itera la colección de entries una sola vez y acumula todos los totales
     * y desgloses. Una sola pasada O(n) — evita múltiples loops sobre el
     * mismo dataset.
     *
     * @param  Collection<int, ExpensesMonthlyReportEntry>  $entries
     */
    private function buildSummary(int $year, int $month, Collection $entries): ExpensesMonthlyReportSummary
    {
        $gastosCount   = 0;
        $gastosTotal   = 0.0;

        $deduciblesCount             = 0;
        $deduciblesTotal             = 0.0;
        $creditoFiscalDeducible      = 0.0;
        $deduciblesIncompletosCount  = 0;

        $noDeduciblesCount = 0;
        $noDeduciblesTotal = 0.0;

        $cashCount    = 0;
        $cashTotal    = 0.0;
        $nonCashCount = 0;
        $nonCashTotal = 0.0;

        // Buckets — keys vivas dinámicamente. Final shape: [key => [label, count, total]].
        /** @var array<string, array{label: string, count: int, total: float}> $byCategory */
        $byCategory = [];
        /** @var array<int, array{name: string, count: int, total: float}> $byEstablishment */
        $byEstablishment = [];
        /** @var array<string, array{label: string, count: int, total: float}> $byPaymentMethod */
        $byPaymentMethod = [];

        foreach ($entries as $entry) {
            $gastosCount++;
            $gastosTotal += $entry->amountTotal;

            // Deducibilidad
            if ($entry->isIsvDeductible) {
                $deduciblesCount++;
                $deduciblesTotal        += $entry->amountTotal;
                $creditoFiscalDeducible += $entry->isvAmount;

                if ($entry->deducibleIncompleto) {
                    $deduciblesIncompletosCount++;
                }
            } else {
                $noDeduciblesCount++;
                $noDeduciblesTotal += $entry->amountTotal;
            }

            // Impacto en caja
            if ($entry->affectsCash) {
                $cashCount++;
                $cashTotal += $entry->amountTotal;
            } else {
                $nonCashCount++;
                $nonCashTotal += $entry->amountTotal;
            }

            // Bucket por categoría
            $catKey = $entry->categoryValue;
            if (! isset($byCategory[$catKey])) {
                $byCategory[$catKey] = [
                    'label' => $entry->categoryLabel,
                    'count' => 0,
                    'total' => 0.0,
                ];
            }
            $byCategory[$catKey]['count']++;
            $byCategory[$catKey]['total'] += $entry->amountTotal;

            // Bucket por método de pago
            $payKey = $entry->paymentMethodValue;
            if (! isset($byPaymentMethod[$payKey])) {
                $byPaymentMethod[$payKey] = [
                    'label' => $entry->paymentMethodLabel,
                    'count' => 0,
                    'total' => 0.0,
                ];
            }
            $byPaymentMethod[$payKey]['count']++;
            $byPaymentMethod[$payKey]['total'] += $entry->amountTotal;

            // Bucket por sucursal — usamos el name como discriminador estable
            // (no tenemos el id en el entry; el name basta para el reporte).
            $estKey = $entry->establishmentName;
            if (! isset($byEstablishment[$estKey])) {
                $byEstablishment[$estKey] = [
                    'name'  => $entry->establishmentName,
                    'count' => 0,
                    'total' => 0.0,
                ];
            }
            $byEstablishment[$estKey]['count']++;
            $byEstablishment[$estKey]['total'] += $entry->amountTotal;
        }

        // Redondeo final a 2 decimales — los acumuladores pueden arrastrar
        // residuos de float que no afectan los datos individuales pero sí
        // los totales reportados.
        $gastosTotal            = round($gastosTotal, 2);
        $deduciblesTotal        = round($deduciblesTotal, 2);
        $creditoFiscalDeducible = round($creditoFiscalDeducible, 2);
        $noDeduciblesTotal      = round($noDeduciblesTotal, 2);
        $cashTotal              = round($cashTotal, 2);
        $nonCashTotal           = round($nonCashTotal, 2);

        foreach ($byCategory as &$bucket) {
            $bucket['total'] = round($bucket['total'], 2);
        }
        unset($bucket);

        foreach ($byPaymentMethod as &$bucket) {
            $bucket['total'] = round($bucket['total'], 2);
        }
        unset($bucket);

        foreach ($byEstablishment as &$bucket) {
            $bucket['total'] = round($bucket['total'], 2);
        }
        unset($bucket);

        // Ordenar buckets por total desc — más relevante arriba en reportes
        uasort($byCategory,      fn ($a, $b) => $b['total'] <=> $a['total']);
        uasort($byPaymentMethod, fn ($a, $b) => $b['total'] <=> $a['total']);
        uasort($byEstablishment, fn ($a, $b) => $b['total'] <=> $a['total']);

        return new ExpensesMonthlyReportSummary(
            year: $year,
            month: $month,
            gastosCount: $gastosCount,
            gastosTotal: $gastosTotal,
            deduciblesCount: $deduciblesCount,
            deduciblesTotal: $deduciblesTotal,
            creditoFiscalDeducible: $creditoFiscalDeducible,
            deduciblesIncompletosCount: $deduciblesIncompletosCount,
            noDeduciblesCount: $noDeduciblesCount,
            noDeduciblesTotal: $noDeduciblesTotal,
            cashCount: $cashCount,
            cashTotal: $cashTotal,
            nonCashCount: $nonCashCount,
            nonCashTotal: $nonCashTotal,
            byCategory: $byCategory,
            byEstablishment: $byEstablishment,
            byPaymentMethod: $byPaymentMethod,
        );
    }
}

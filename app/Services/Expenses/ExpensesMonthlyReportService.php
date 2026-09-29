<?php

declare(strict_types=1);

namespace App\Services\Expenses;

use App\Models\Purchase;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Service que construye el DTO `ExpensesMonthlyReport` para un período.
 *
 * Fuentes (desde "todo en Compras", 2026-09-28):
 *   - Compras tipo Gasto CONFIRMADAS del mes (los borradores todavía no son
 *     un gasto; las anuladas ya no lo son).
 *   - Comisiones de tarjeta de las ventas del mes (`sales.card_fee_amount`).
 * Dos queries con eager load cubren detalle y resumen; los totales se
 * acumulan en PHP en una sola pasada.
 *
 * POR QUÉ ITERACIÓN EN PHP Y NO SQL AGGREGATION:
 *   - Volumen esperado: decenas a pocos cientos de gastos por mes en un
 *     solo negocio. Iterarlos en PHP es trivial y evita 5 queries de
 *     agregación por categoría/método/sucursal/deducibilidad.
 *   - La regla de "deducible incompleto" vive en
 *     `ExpensesMonthlyReportEntry`; en SQL habría que duplicarla.
 *
 * ÍNDICES: `purchases_kind_status_date_index (kind, status, date)` y
 * `sales.date` — ambos filtros son rangos de fecha (whereBetween), no
 * whereMonth, para que el índice se use.
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
        $from = CarbonImmutable::create($year, $month, 1)->startOfMonth();
        $to = $from->endOfMonth();

        $purchases = Purchase::query()
            ->gastos()
            ->confirmadas()
            ->with([
                'establishment:id,name',
                'supplier:id,name,rtn',
                'createdBy:id,name',
            ])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($establishmentId !== null, fn ($query) => $query->where('establishment_id', $establishmentId))
            ->get();

        $cardFees = Sale::query()
            ->where('card_fee_amount', '>', 0)
            ->with([
                'establishment:id,name',
                'createdBy:id,name',
            ])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($establishmentId !== null, fn ($query) => $query->where('establishment_id', $establishmentId))
            ->get();

        $entries = $purchases
            ->map(fn (Purchase $purchase) => ExpensesMonthlyReportEntry::fromPurchase($purchase))
            ->concat($cardFees->map(fn (Sale $sale) => ExpensesMonthlyReportEntry::fromCardFee($sale)))
            ->sortBy([
                fn (ExpensesMonthlyReportEntry $a, ExpensesMonthlyReportEntry $b) => $a->expenseDate <=> $b->expenseDate,
                fn (ExpensesMonthlyReportEntry $a, ExpensesMonthlyReportEntry $b) => $a->reference <=> $b->reference,
            ])
            ->values();

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
        $gastosCount = 0;
        $gastosTotal = 0.0;

        $deduciblesCount = 0;
        $deduciblesTotal = 0.0;
        $creditoFiscalDeducible = 0.0;
        $deduciblesIncompletosCount = 0;

        $noDeduciblesCount = 0;
        $noDeduciblesTotal = 0.0;

        $cashCount = 0;
        $cashTotal = 0.0;
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
                $deduciblesTotal += $entry->amountTotal;
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
                    'name' => $entry->establishmentName,
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
        $gastosTotal = round($gastosTotal, 2);
        $deduciblesTotal = round($deduciblesTotal, 2);
        $creditoFiscalDeducible = round($creditoFiscalDeducible, 2);
        $noDeduciblesTotal = round($noDeduciblesTotal, 2);
        $cashTotal = round($cashTotal, 2);
        $nonCashTotal = round($nonCashTotal, 2);

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
        uasort($byCategory, fn ($a, $b) => $b['total'] <=> $a['total']);
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

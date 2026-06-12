<?php

namespace App\Services\Dashboard;

use App\Enums\MovementType;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Servicio centralizado de métricas del dashboard.
 *
 * Todas las queries se cachean con TTL corto (5 min) para:
 * - Evitar N queries pesadas al abrir el dashboard
 * - Permitir auto-refresh sin golpear la DB cada vez
 *
 * Cada método representa UNA métrica y es independiente — así el polling
 * de cada widget solo invalida lo que cambia.
 */
class DashboardStatsService
{
    /** TTL por defecto para las métricas: 5 minutos */
    private const CACHE_TTL = 300;

    private const CACHE_PREFIX = 'dashboard_stats:';

    // ─── Ventas (monto + conteo + delta) ─────────────────────────────────

    /**
     * Métricas de ventas del día actual vs ayer.
     *
     * @return array{total: float, count: int, delta_percent: float|null, previous_total: float}
     */
    public function salesToday(): array
    {
        return $this->remember('sales_today', function () {
            $today = $this->salesMetrics(Carbon::today(), Carbon::today()->endOfDay());
            $yesterday = $this->salesMetrics(
                Carbon::yesterday(),
                Carbon::yesterday()->endOfDay()
            );

            return [
                'total' => $today['total'],
                'count' => $today['count'],
                'previous_total' => $yesterday['total'],
                'delta_percent' => $this->deltaPercent($today['total'], $yesterday['total']),
            ];
        });
    }

    /**
     * Métricas de ventas del mes actual vs mes anterior al mismo día.
     *
     * @return array{total: float, count: int, delta_percent: float|null, previous_total: float}
     */
    public function salesThisMonth(): array
    {
        return $this->remember('sales_this_month', function () {
            $now = Carbon::now();

            $current = $this->salesMetrics($now->copy()->startOfMonth(), $now);

            // Mes anterior: mismo rango de días (ej: 1-15 del mes pasado)
            $previousStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
            $previousEnd = $previousStart->copy()->addDays($now->day - 1)->endOfDay();

            $previous = $this->salesMetrics($previousStart, $previousEnd);

            return [
                'total' => $current['total'],
                'count' => $current['count'],
                'previous_total' => $previous['total'],
                'delta_percent' => $this->deltaPercent($current['total'], $previous['total']),
            ];
        });
    }

    // ─── Sparkline — últimos 14 días de ventas ────────────────────────────

    /**
     * Totales diarios de los últimos 14 días (para sparkline en stat card).
     *
     * @return array<int, float>
     */
    public function salesSparkline14Days(): array
    {
        return $this->remember('sales_sparkline_14d', function () {
            $start = Carbon::today()->subDays(13);
            $end = Carbon::today()->endOfDay();

            $rows = Sale::query()
                ->select(
                    DB::raw('DATE(date) as day'),
                    DB::raw('COALESCE(SUM(total), 0) as total_dia')
                )
                ->where('status', SaleStatus::Completada)
                ->whereBetween('date', [$start, $end])
                ->groupBy('day')
                ->pluck('total_dia', 'day');

            $data = [];
            for ($i = 0; $i < 14; $i++) {
                $key = $start->copy()->addDays($i)->toDateString();
                $data[] = round((float) ($rows[$key] ?? 0), 2);
            }

            return $data;
        });
    }

    // ─── Métricas financieras ─────────────────────────────────────────────

    /**
     * Ganancia bruta del mes = revenue - costo de ventas.
     *
     * Fuente del costo según el tipo de línea:
     *   - Línea CON producto (POS y piezas de inventario de reparaciones):
     *     snapshot `unit_cost` del movimiento `SalidaVenta` del kardex —
     *     costo promedio ponderado exacto al momento de la venta, no el
     *     `cost_price` actual del producto.
     *   - Línea SIN producto (honorarios y piezas externas de reparaciones):
     *     `sale_items.unit_cost` copiado desde la cotización al entregar.
     *     Honorarios no tienen costo (NULL → 0) = ganancia pura.
     *
     * Honestidad estadística: las líneas de producto pre-migración sin
     * snapshot de kardex se excluyen del cálculo (revenue y cost) — no se
     * inventan costos. Las líneas sin producto siempre entran (su costo
     * NULL significa "sin costo", no "costo desconocido").
     *
     * @return array{gross_profit: float, margin_percent: float, revenue: float, cost: float}
     */
    public function grossProfitThisMonth(): array
    {
        return $this->remember('gross_profit_month', function () {
            $start = Carbon::now()->startOfMonth();
            $end = Carbon::now()->endOfDay();

            // LEFT JOIN al kardex: las líneas sin producto no tienen movimiento
            // SalidaVenta y antes quedaban excluidas por el INNER JOIN — el
            // dashboard ignoraba todo el ingreso de honorarios y piezas
            // externas de reparaciones. Una sola query agregada, sin N+1.
            $row = SaleItem::query()
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->leftJoin('inventory_movements as im', function ($join) {
                    $join->on('im.reference_id', '=', 'sales.id')
                        ->whereColumn('im.product_id', 'sale_items.product_id')
                        ->where('im.reference_type', Sale::class)
                        ->where('im.type', MovementType::SalidaVenta->value);
                })
                ->where('sales.status', SaleStatus::Completada)
                ->whereBetween('sales.date', [$start, $end])
                ->where(function ($query) {
                    $query->whereNull('sale_items.product_id')   // honorarios / pieza externa
                        ->orWhereNotNull('im.unit_cost');        // producto con kardex
                })
                ->selectRaw('
                    COALESCE(SUM(sale_items.subtotal), 0) as revenue,
                    COALESCE(SUM(sale_items.quantity * COALESCE(im.unit_cost, sale_items.unit_cost, 0)), 0) as cost
                ')
                ->first();

            $revenue = (float) ($row->revenue ?? 0);
            $cost = (float) ($row->cost ?? 0);
            $profit = $revenue - $cost;
            $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

            return [
                'revenue' => round($revenue, 2),
                'cost' => round($cost, 2),
                'gross_profit' => round($profit, 2),
                'margin_percent' => round($margin, 2),
            ];
        });
    }

    /**
     * Total de gastos operativos del mes en curso.
     *
     * Incluye TODOS los gastos del mes — deducibles y no deducibles. La
     * distinción "deducible" aplica solo al ISV-353 (crédito fiscal). Para
     * P&L (utilidad neta), todo gasto reduce ganancia sin importar si tiene
     * crédito fiscal asociado.
     *
     * Filtra por `expense_date` (no por created_at) para alinear con el
     * período fiscal correcto — un gasto registrado tarde con fecha del mes
     * pasado pertenece al mes pasado.
     */
    public function expensesThisMonth(): float
    {
        return $this->remember('expenses_month', function () {
            $now = Carbon::now();

            return (float) Expense::query()
                ->forMonth($now->year, $now->month)
                ->sum('amount_total');
        });
    }

    /**
     * Utilidad neta del mes = ganancia bruta − gastos operativos.
     *
     * Ganancia bruta = revenue − COGS (ya calculado por grossProfitThisMonth).
     * Gastos operativos = suma de Expense.amount_total del mes.
     *
     * El COGS NO se vuelve a restar acá — ya está descontado en la ganancia
     * bruta. Tampoco se mezcla el ISV (crédito fiscal de compras o débito de
     * ventas) — eso es trazabilidad SAR, no P&L.
     *
     * net_margin_percent = (net_profit / revenue) × 100. Útil para juzgar la
     * salud real del negocio: dos meses pueden tener la misma ganancia bruta
     * pero muy distinto net_margin si los gastos operativos varían.
     *
     * @return array{
     *     gross_profit: float,
     *     expenses: float,
     *     net_profit: float,
     *     net_margin_percent: float,
     *     revenue: float
     * }
     */
    public function netProfitThisMonth(): array
    {
        return $this->remember('net_profit_month', function () {
            $gross = $this->grossProfitThisMonth();
            $expenses = $this->expensesThisMonth();

            $revenue = (float) $gross['revenue'];
            $grossProfit = (float) $gross['gross_profit'];
            $netProfit = round($grossProfit - $expenses, 2);

            $netMargin = $revenue > 0
                ? round(($netProfit / $revenue) * 100, 2)
                : 0.0;

            return [
                'gross_profit' => $grossProfit,
                'expenses' => round($expenses, 2),
                'net_profit' => $netProfit,
                'net_margin_percent' => $netMargin,
                'revenue' => round($revenue, 2),
            ];
        });
    }

    /**
     * Ticket promedio del mes (total promedio por venta completada).
     */
    public function averageTicketThisMonth(): float
    {
        return $this->remember('avg_ticket_month', function () {
            $start = Carbon::now()->startOfMonth();
            $end = Carbon::now()->endOfDay();

            $avg = Sale::query()
                ->where('status', SaleStatus::Completada)
                ->whereBetween('date', [$start, $end])
                ->avg('total');

            return round((float) ($avg ?? 0), 2);
        });
    }

    /**
     * Clientes nuevos registrados en el mes actual.
     */
    public function newCustomersThisMonth(): int
    {
        return $this->remember('new_customers_month', function () {
            return Customer::query()
                ->whereBetween('created_at', [
                    Carbon::now()->startOfMonth(),
                    Carbon::now()->endOfDay(),
                ])
                ->count();
        });
    }

    // ─── Inventario y compras ─────────────────────────────────────────────

    /**
     * @return array{low_stock: int, out_of_stock: int}
     */
    public function stockAlerts(): array
    {
        return $this->remember('stock_alerts', function () {
            $lowStock = Product::query()->active()->lowStock()->count();
            $outOfStock = Product::query()->active()->outOfStock()->count();

            return [
                'low_stock' => $lowStock,
                'out_of_stock' => $outOfStock,
            ];
        });
    }

    /**
     * @return array{count: int, total: float}
     */
    public function pendingPurchases(): array
    {
        return $this->remember('pending_purchases', function () {
            // Solo compras a crédito pendientes: las de contado nacen como Pagada,
            // pero filtramos explícitamente por credit_days > 0 como cinturón de
            // seguridad contra datos legacy y para dejar la intención del query clara.
            $row = Purchase::query()
                ->where('payment_status', PaymentStatus::Pendiente)
                ->where('credit_days', '>', 0)
                ->selectRaw('COUNT(*) as total_count, COALESCE(SUM(total), 0) as total_amount')
                ->first();

            return [
                'count' => (int) ($row->total_count ?? 0),
                'total' => round((float) ($row->total_amount ?? 0), 2),
            ];
        });
    }

    // ─── Invalidación (para cuando se hace una venta/compra nueva) ────────

    public static function invalidate(): void
    {
        $keys = [
            'sales_today', 'sales_this_month', 'sales_sparkline_14d',
            'gross_profit_month', 'expenses_month', 'net_profit_month',
            'avg_ticket_month', 'new_customers_month',
            'stock_alerts', 'pending_purchases',
        ];

        foreach ($keys as $key) {
            Cache::forget(self::CACHE_PREFIX . $key);
        }
    }

    // ─── Helpers privados ─────────────────────────────────────────────────

    /**
     * @return array{total: float, count: int}
     */
    private function salesMetrics(Carbon $start, Carbon $end): array
    {
        $row = Sale::query()
            ->where('status', SaleStatus::Completada)
            ->whereBetween('date', [$start, $end])
            ->selectRaw('COALESCE(SUM(total), 0) as total, COUNT(*) as count')
            ->first();

        return [
            'total' => round((float) ($row->total ?? 0), 2),
            'count' => (int) ($row->count ?? 0),
        ];
    }

    /**
     * Calcula el % de cambio entre dos valores.
     * Retorna null si el valor anterior era 0 (evita división por cero
     * y "infinito%" que confunde al usuario).
     */
    private function deltaPercent(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function remember(string $key, callable $callback)
    {
        return Cache::remember(
            self::CACHE_PREFIX . $key,
            self::CACHE_TTL,
            $callback
        );
    }
}

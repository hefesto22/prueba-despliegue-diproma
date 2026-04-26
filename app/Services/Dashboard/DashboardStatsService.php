<?php

namespace App\Services\Dashboard;

use App\Enums\MovementType;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Customer;
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
     * El costo usa el snapshot `unit_cost` del movimiento `SalidaVenta`
     * correspondiente a cada `sale_item` — costo promedio ponderado exacto
     * al momento de la venta, no el `cost_price` actual del producto.
     *
     * Las ventas pre-migración sin `unit_cost` se excluyen del cálculo
     * (revenue y cost) por honestidad estadística: no se inventan costos.
     *
     * @return array{gross_profit: float, margin_percent: float, revenue: float, cost: float}
     */
    public function grossProfitThisMonth(): array
    {
        return $this->remember('gross_profit_month', function () {
            $start = Carbon::now()->startOfMonth();
            $end = Carbon::now()->endOfDay();

            // JOIN al movimiento de kardex (SalidaVenta) para usar el unit_cost
            // histórico. Una sola query agregada, sin N+1.
            $row = SaleItem::query()
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->join('inventory_movements as im', function ($join) {
                    $join->on('im.reference_id', '=', 'sales.id')
                        ->whereColumn('im.product_id', 'sale_items.product_id')
                        ->where('im.reference_type', Sale::class)
                        ->where('im.type', MovementType::SalidaVenta->value);
                })
                ->where('sales.status', SaleStatus::Completada)
                ->whereBetween('sales.date', [$start, $end])
                ->whereNotNull('im.unit_cost')
                ->selectRaw('
                    COALESCE(SUM(sale_items.subtotal), 0) as revenue,
                    COALESCE(SUM(sale_items.quantity * im.unit_cost), 0) as cost
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
            'gross_profit_month', 'avg_ticket_month', 'new_customers_month',
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

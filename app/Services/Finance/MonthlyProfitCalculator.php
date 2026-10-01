<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Cálculo de la utilidad para cualquier período.
 *
 * Antes vivía dentro de DashboardStatsService atado a "este mes"; el diezmo
 * necesita el mismo cálculo para meses pasados. Una sola implementación evita
 * que el Escritorio y el diezmo den cifras distintas para el mismo mes.
 *
 * Sin caché: el Escritorio cachea por su cuenta; el diezmo necesita las cifras
 * del momento en que se guarda.
 */
class MonthlyProfitCalculator
{
    public function forMonth(int $year, int $month): MonthlyProfit
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfMonth();
        $end = $start->endOfMonth();

        $gross = $this->grossProfitBetween($start, $end);
        $expenses = $this->operatingExpensesBetween($start, $end);

        return new MonthlyProfit(
            year: $year,
            month: $month,
            revenue: $gross['revenue'],
            cost: $gross['cost'],
            expensePurchases: $expenses['expense_purchases'],
            cardFees: $expenses['card_fees'],
        );
    }

    /**
     * Ventas sin ISV y su costo histórico, de ventas completadas.
     *
     * Fuentes del costo, en orden:
     *   - Producto del catálogo: `inventory_movements.unit_cost` del SalidaVenta
     *     (costo NETO al momento de vender).
     *   - Línea sin producto (honorarios, pieza externa de reparación):
     *     `sale_items.unit_cost`; honorarios sin costo (NULL → 0).
     *
     * Las líneas de producto pre-migración sin snapshot de kardex se excluyen
     * (revenue y costo): no se inventan costos.
     *
     * LEFT JOIN al kardex en una sola query agregada, sin N+1.
     *
     * @return array{revenue: float, cost: float}
     */
    public function grossProfitBetween(CarbonInterface $start, CarbonInterface $end): array
    {
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

        return [
            'revenue' => round((float) ($row->revenue ?? 0), 2),
            'cost' => round((float) ($row->cost ?? 0), 2),
        ];
    }

    /**
     * Gastos operativos registrados en el sistema.
     *
     *   - Compras tipo Gasto CONFIRMADAS, por su SUBTOTAL: el ISV de una
     *     factura se recupera como crédito fiscal, no es gasto. En un Recibo
     *     Interno subtotal = total.
     *   - La mercadería NO suma aquí: entra por el costo de lo vendido.
     *   - Comisiones de tarjeta de las ventas, aunque la venta se haya anulado
     *     después: el banco ya las cobró.
     *
     * Filtra por la fecha del documento (no created_at).
     *
     * @return array{expense_purchases: float, card_fees: float}
     */
    public function operatingExpensesBetween(CarbonInterface $start, CarbonInterface $end): array
    {
        $expensePurchases = (float) Purchase::query()
            ->gastos()
            ->confirmadas()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->sum('subtotal');

        $cardFees = (float) Sale::query()
            ->whereBetween('date', [$start, $end])
            ->sum('card_fee_amount');

        return [
            'expense_purchases' => round($expensePurchases, 2),
            'card_fees' => round($cardFees, 2),
        ];
    }
}

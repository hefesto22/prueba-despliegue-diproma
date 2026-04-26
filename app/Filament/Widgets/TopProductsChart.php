<?php

namespace App\Filament\Widgets;

use App\Enums\SaleStatus;
use App\Models\SaleItem;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TopProductsChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Top 10 Productos Más Vendidos';

    protected ?string $description = 'Por cantidad vendida en el mes actual';

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'xl' => 1,
    ];

    protected ?string $pollingInterval = '300s';

    protected ?string $maxHeight = '400px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return Cache::remember(
            'dashboard_stats:top_products',
            300,
            fn () => $this->buildData()
        );
    }

    /**
     * @return array{labels: array<string>, datasets: array<int, array<string, mixed>>}
     */
    private function buildData(): array
    {
        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfDay();

        // Agregación por SQL: JOIN + GROUP BY + LIMIT 10 — el motor hace el trabajo
        $rows = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', SaleStatus::Completada)
            ->whereBetween('sales.date', [$start, $end])
            ->select(
                'products.name as product_name',
                DB::raw('SUM(sale_items.quantity) as total_quantity'),
                DB::raw('SUM(sale_items.total) as total_revenue')
            )
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get();

        if ($rows->isEmpty()) {
            return [
                'labels' => ['Sin ventas en el período'],
                'datasets' => [[
                    'label' => 'Unidades',
                    'data' => [0],
                    'backgroundColor' => 'rgba(156, 163, 175, 0.5)',
                ]],
            ];
        }

        // Truncar nombres largos para legibilidad del eje
        $labels = $rows->map(
            fn ($row) => strlen($row->product_name) > 35
                ? substr($row->product_name, 0, 32) . '...'
                : $row->product_name
        )->toArray();

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Unidades vendidas',
                    'data' => $rows->pluck('total_quantity')->map(fn ($q) => (int) $q)->toArray(),
                    'backgroundColor' => 'rgba(245, 158, 11, 0.7)',
                    'borderColor' => 'rgb(245, 158, 11)',
                    'borderWidth' => 1,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
        ];
    }
}

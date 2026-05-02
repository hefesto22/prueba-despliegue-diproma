<?php

namespace App\Filament\Widgets;

use App\Enums\SaleStatus;
use App\Models\SaleItem;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SalesByCategoryChart extends ChartWidget
{
    protected static ?int $sort = 5;

    protected ?string $heading = 'Ventas por Categoría';

    protected ?string $description = 'Distribución del mes actual';

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'xl' => 1,
    ];

    protected ?string $pollingInterval = '300s';

    protected ?string $maxHeight = '400px';

    /**
     * Distribución de ventas por categoría. KPI de gestión comercial — solo
     * visible para super_admin / admin / contador. El cajero no necesita
     * vista agregada por categoría para operar.
     */
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole(['super_admin', 'admin', 'contador']);
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        return Cache::remember(
            'dashboard_stats:sales_by_category',
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

        $rows = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.status', SaleStatus::Completada)
            ->whereBetween('sales.date', [$start, $end])
            ->select(
                DB::raw("COALESCE(categories.name, 'Sin categoría') as category_name"),
                DB::raw('SUM(sale_items.total) as total_revenue')
            )
            ->groupBy('category_name')
            ->orderByDesc('total_revenue')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'labels' => ['Sin datos'],
                'datasets' => [[
                    'data' => [1],
                    'backgroundColor' => ['rgba(156, 163, 175, 0.5)'],
                ]],
            ];
        }

        // Paleta de colores consistente (amber primary + complementarios)
        $palette = [
            'rgb(245, 158, 11)',   // amber-500
            'rgb(59, 130, 246)',   // blue-500
            'rgb(16, 185, 129)',   // emerald-500
            'rgb(168, 85, 247)',   // purple-500
            'rgb(236, 72, 153)',   // pink-500
            'rgb(239, 68, 68)',    // red-500
            'rgb(14, 165, 233)',   // sky-500
            'rgb(34, 197, 94)',    // green-500
            'rgb(250, 204, 21)',   // yellow-400
            'rgb(107, 114, 128)',  // gray-500
        ];

        $labels = $rows->pluck('category_name')->toArray();
        $data = $rows->pluck('total_revenue')->map(fn ($v) => round((float) $v, 2))->toArray();
        $colors = array_slice($palette, 0, count($labels));

        // Si hay más categorías que colores, reciclar la paleta
        while (count($colors) < count($labels)) {
            $colors = array_merge($colors, $palette);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => array_slice($colors, 0, count($labels)),
                    'borderWidth' => 2,
                    'borderColor' => 'rgba(17, 24, 39, 0.8)',
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'right',
                ],
            ],
            'cutout' => '60%',
        ];
    }
}

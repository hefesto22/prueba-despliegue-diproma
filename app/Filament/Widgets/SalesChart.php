<?php

namespace App\Filament\Widgets;

use App\Enums\SaleStatus;
use App\Models\Sale;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SalesChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Tendencia de Ventas';

    protected ?string $description = 'Comparativa con el período anterior';

    protected int | string | array $columnSpan = 'full';

    protected ?string $pollingInterval = '300s';

    protected ?string $maxHeight = '320px';

    public ?string $filter = '30d';

    /**
     * Tendencia de ventas con período comparativo. Información ejecutiva —
     * solo visible para super_admin / admin / contador. El cajero ve sus
     * ventas concretas en el módulo Ventas, no necesita la tendencia global.
     */
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole(['super_admin', 'admin', 'contador']);
    }

    protected function getFilters(): ?array
    {
        return [
            '7d' => 'Últimos 7 días',
            '30d' => 'Últimos 30 días',
            '3m' => 'Últimos 3 meses',
            '1y' => 'Último año',
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $filter = $this->filter ?? '30d';

        // Cachear por filtro para evitar recalcular en cada polling
        return Cache::remember(
            "dashboard_stats:sales_chart:{$filter}",
            300,
            fn () => $this->buildChartData($filter)
        );
    }

    /**
     * @return array{labels: array<string>, datasets: array<int, array<string, mixed>>}
     */
    private function buildChartData(string $filter): array
    {
        [$days, $granularity, $dateFormat] = match ($filter) {
            '7d' => [7, 'day', 'd/m'],
            '30d' => [30, 'day', 'd/m'],
            '3m' => [90, 'week', 'W-Y'],
            '1y' => [365, 'month', 'M Y'],
            default => [30, 'day', 'd/m'],
        };

        $end = Carbon::today()->endOfDay();
        $start = Carbon::today()->subDays($days - 1);

        // Período anterior (mismo tamaño, para comparativa)
        $previousEnd = $start->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();

        $current = $this->fetchAggregated($start, $end, $granularity);
        $previous = $this->fetchAggregated($previousStart, $previousEnd, $granularity);

        $labels = [];
        $currentData = [];
        $previousData = [];

        $buckets = $this->buildBuckets($start, $end, $granularity);

        foreach ($buckets as $i => $bucket) {
            $labels[] = $this->formatBucket($bucket, $granularity);
            $currentData[] = round((float) ($current[$bucket] ?? 0), 2);

            // Para la línea comparativa — alineada por posición, no por fecha
            $previousBuckets = $this->buildBuckets($previousStart, $previousEnd, $granularity);
            $previousBucket = $previousBuckets[$i] ?? null;
            $previousData[] = $previousBucket
                ? round((float) ($previous[$previousBucket] ?? 0), 2)
                : 0;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Período actual',
                    'data' => $currentData,
                    'borderColor' => 'rgb(245, 158, 11)',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Período anterior',
                    'data' => $previousData,
                    'borderColor' => 'rgba(156, 163, 175, 0.8)',
                    'backgroundColor' => 'transparent',
                    'borderDash' => [5, 5],
                    'fill' => false,
                    'tension' => 0.3,
                    'borderWidth' => 1.5,
                ],
            ],
        ];
    }

    /**
     * Query agregada con GROUP BY en SQL — O(1) memoria sin importar el volumen.
     *
     * @return array<string, float>
     */
    private function fetchAggregated(Carbon $start, Carbon $end, string $granularity): array
    {
        $groupExpr = match ($granularity) {
            'day' => "DATE(date)",
            'week' => "DATE_FORMAT(date, '%x-%v')",   // ISO year-week
            'month' => "DATE_FORMAT(date, '%Y-%m')",
            default => "DATE(date)",
        };

        return Sale::query()
            ->select(
                DB::raw("{$groupExpr} as bucket"),
                DB::raw('COALESCE(SUM(total), 0) as total_bucket')
            )
            ->where('status', SaleStatus::Completada)
            ->whereBetween('date', [$start, $end])
            ->groupBy('bucket')
            ->pluck('total_bucket', 'bucket')
            ->toArray();
    }

    /**
     * @return array<int, string>
     */
    private function buildBuckets(Carbon $start, Carbon $end, string $granularity): array
    {
        $buckets = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $buckets[] = match ($granularity) {
                'day' => $cursor->toDateString(),
                'week' => $cursor->format('o-W'),
                'month' => $cursor->format('Y-m'),
                default => $cursor->toDateString(),
            };

            match ($granularity) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };
        }

        return array_values(array_unique($buckets));
    }

    private function formatBucket(string $bucket, string $granularity): string
    {
        return match ($granularity) {
            'day' => Carbon::parse($bucket)->format('d/m'),
            'week' => 'Sem ' . substr($bucket, -2),
            'month' => Carbon::createFromFormat('Y-m', $bucket)->translatedFormat('M Y'),
            default => $bucket,
        };
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
        ];
    }
}

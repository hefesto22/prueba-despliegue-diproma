<?php

namespace App\Filament\Widgets;

use App\Services\Dashboard\DashboardStatsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '120s';

    /**
     * Se inyecta via boot() — Livewire 3 soporta method injection en boot().
     * Propiedad protected para que NO se serialice entre requests (solo las
     * public lo hacen) — el container resuelve fresh cada render.
     */
    protected DashboardStatsService $stats;

    public function boot(DashboardStatsService $stats): void
    {
        $this->stats = $stats;
    }

    /**
     * KPIs operativos del negocio (ventas globales, stock, compras pendientes).
     * Solo visibles para roles que necesitan vista ejecutiva — el cajero opera
     * en POS y ve sus propias ventas en el módulo correspondiente, no necesita
     * (ni debe ver) los totales globales del negocio.
     */
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole(['super_admin', 'admin', 'contador']);
    }

    protected function getStats(): array
    {
        $today = $this->stats->salesToday();
        $month = $this->stats->salesThisMonth();
        $stock = $this->stats->stockAlerts();
        $purchases = $this->stats->pendingPurchases();
        $sparkline = $this->stats->salesSparkline14Days();

        return [
            // 1️⃣ Ventas de hoy — con delta vs ayer + sparkline
            Stat::make('Ventas de Hoy', 'L. ' . number_format($today['total'], 2))
                ->description($this->deltaDescription(
                    $today['delta_percent'],
                    $today['previous_total'],
                    'vs. ayer'
                ))
                ->descriptionIcon($this->deltaIcon($today['delta_percent']))
                ->chart($sparkline)
                ->color($this->deltaColor($today['delta_percent'])),

            // 2️⃣ Ventas del mes — con delta vs mes anterior
            Stat::make('Ventas del Mes', 'L. ' . number_format($month['total'], 2))
                ->description($this->deltaDescription(
                    $month['delta_percent'],
                    $month['previous_total'],
                    'vs. mes anterior'
                ))
                ->descriptionIcon($this->deltaIcon($month['delta_percent']))
                ->chart($sparkline)
                ->color($this->deltaColor($month['delta_percent'])),

            // 3️⃣ Stock bajo — con badge de críticos
            Stat::make('Productos con Stock Bajo', (string) $stock['low_stock'])
                ->description(
                    $stock['out_of_stock'] > 0
                        ? "⚠ {$stock['out_of_stock']} producto(s) agotado(s)"
                        : 'Todo en stock saludable'
                )
                ->descriptionIcon(
                    $stock['low_stock'] > 0
                        ? 'heroicon-m-exclamation-triangle'
                        : 'heroicon-m-check-circle'
                )
                ->color(match (true) {
                    $stock['out_of_stock'] > 0 => 'danger',
                    $stock['low_stock'] > 0 => 'warning',
                    default => 'success',
                }),

            // 4️⃣ Compras pendientes — con monto total por pagar
            Stat::make('Compras Pendientes', (string) $purchases['count'])
                ->description('L. ' . number_format($purchases['total'], 2) . ' por pagar')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($purchases['count'] > 0 ? 'danger' : 'success'),
        ];
    }

    /**
     * Genera la descripción de la stat card con el delta.
     * Ej: "+12.5% vs. ayer", "-3.2% vs. mes anterior", "Sin datos previos"
     */
    private function deltaDescription(?float $delta, float $previous, string $suffix): string
    {
        if ($delta === null) {
            return $previous <= 0 ? "Sin ventas previas" : "{$suffix}";
        }

        $sign = $delta >= 0 ? '+' : '';
        return "{$sign}{$delta}% {$suffix}";
    }

    private function deltaIcon(?float $delta): string
    {
        if ($delta === null) {
            return 'heroicon-m-minus';
        }

        return $delta >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';
    }

    private function deltaColor(?float $delta): string
    {
        if ($delta === null) {
            return 'gray';
        }

        return $delta >= 0 ? 'success' : 'danger';
    }
}

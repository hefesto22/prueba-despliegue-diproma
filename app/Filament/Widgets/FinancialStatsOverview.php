<?php

namespace App\Filament\Widgets;

use App\Services\Dashboard\DashboardStatsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinancialStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '300s';

    protected ?string $heading = 'Rentabilidad del Mes';

    /**
     * Se inyecta via boot() — Livewire 3 soporta method injection en boot().
     * Propiedad protected para que NO se serialice entre requests.
     */
    protected DashboardStatsService $stats;

    public function boot(DashboardStatsService $stats): void
    {
        $this->stats = $stats;
    }

    /**
     * KPIs financieros (ganancia bruta, margen, ticket promedio, clientes nuevos).
     * Información sensible de rentabilidad — restringida a super_admin / admin /
     * contador. El cajero NO debe ver márgenes ni ganancias del negocio.
     */
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole(['super_admin', 'admin', 'contador']);
    }

    protected function getStats(): array
    {
        $profit = $this->stats->grossProfitThisMonth();
        $avgTicket = $this->stats->averageTicketThisMonth();
        $newCustomers = $this->stats->newCustomersThisMonth();

        return [
            // Ganancia bruta del mes
            Stat::make('Ganancia Bruta', 'L. ' . number_format($profit['gross_profit'], 2))
                ->description(
                    'De L. ' . number_format($profit['revenue'], 2) . ' facturado'
                )
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($profit['gross_profit'] > 0 ? 'success' : 'gray'),

            // Margen de ganancia
            Stat::make('Margen Promedio', number_format($profit['margin_percent'], 1) . '%')
                ->description($this->marginDescription($profit['margin_percent']))
                ->descriptionIcon($this->marginIcon($profit['margin_percent']))
                ->color($this->marginColor($profit['margin_percent'])),

            // Ticket promedio
            Stat::make('Ticket Promedio', 'L. ' . number_format($avgTicket, 2))
                ->description('Valor promedio por venta')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('primary'),

            // Clientes nuevos
            Stat::make('Clientes Nuevos', (string) $newCustomers)
                ->description('Registrados este mes')
                ->descriptionIcon('heroicon-m-user-plus')
                ->color($newCustomers > 0 ? 'success' : 'gray'),
        ];
    }

    /**
     * Criterio de salud del margen según estándar de retail de tecnología en Honduras:
     * < 10% indica riesgo (no cubre gastos operativos)
     * 10-20% saludable
     * > 20% excelente
     */
    private function marginDescription(float $margin): string
    {
        return match (true) {
            $margin >= 20 => 'Margen excelente',
            $margin >= 10 => 'Margen saludable',
            $margin > 0 => 'Margen bajo — revisar precios',
            default => 'Sin ventas en el período',
        };
    }

    private function marginIcon(float $margin): string
    {
        return match (true) {
            $margin >= 20 => 'heroicon-m-sparkles',
            $margin >= 10 => 'heroicon-m-check-circle',
            $margin > 0 => 'heroicon-m-exclamation-triangle',
            default => 'heroicon-m-minus',
        };
    }

    private function marginColor(float $margin): string
    {
        return match (true) {
            $margin >= 20 => 'success',
            $margin >= 10 => 'primary',
            $margin > 0 => 'warning',
            default => 'gray',
        };
    }
}

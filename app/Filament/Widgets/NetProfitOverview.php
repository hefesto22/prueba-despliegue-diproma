<?php

namespace App\Filament\Widgets;

use App\Services\Dashboard\DashboardStatsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Utilidad Neta del Mes — el numerito que importa.
 *
 * Muestra la cadena: Ganancia Bruta − Gastos = Utilidad Neta.
 * Es lo que queda después de costear el inventario vendido y de pagar
 * todos los gastos operativos del mes (renta, salarios, luz, transporte,
 * etc.). Si este número es negativo, el negocio está perdiendo plata
 * aunque haya ventas.
 *
 * No mezcla ISV de ningún tipo:
 *   - Crédito fiscal de compras (purchases.isv) es activo tributario, no
 *     reduce P&L.
 *   - ISV cobrado en ventas no es ingreso del negocio — es lo que se le
 *     debe al SAR.
 *   - El ISV neto a pagar (cobrado − crédito) se trata aparte en el
 *     Reporte ISV mensual.
 *
 * Permisos: super_admin / admin / contador. El cajero NO ve utilidad neta
 * ni gastos — información sensible que no aplica a su rol operativo.
 */
class NetProfitOverview extends StatsOverviewWidget
{
    // Después de FinancialStatsOverview (sort 2) — la lectura natural es
    // primero los KPIs de venta y rentabilidad bruta, después la utilidad
    // neta que cierra el cuadro financiero del mes.
    protected static ?int $sort = 3;

    protected ?string $pollingInterval = '300s';

    protected ?string $heading = 'Utilidad Neta del Mes';

    protected ?string $description = 'Ganancia bruta menos gastos operativos del período';

    /**
     * Inyectado vía boot() — Livewire 3 soporta method injection.
     * Property protected para que NO se serialice entre requests.
     */
    protected DashboardStatsService $stats;

    public function boot(DashboardStatsService $stats): void
    {
        $this->stats = $stats;
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole(['super_admin', 'admin', 'contador']);
    }

    protected function getStats(): array
    {
        $netProfit = $this->stats->netProfitThisMonth();

        return [
            // Ganancia bruta — informativa, repetida desde el widget anterior
            // para que la cadena de la fórmula sea legible en este bloque.
            Stat::make('Ganancia Bruta', 'L. ' . number_format($netProfit['gross_profit'], 2))
                ->description('Ventas menos costo del producto vendido')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color($netProfit['gross_profit'] > 0 ? 'success' : 'gray'),

            // Gastos del mes — siempre se restan, así que el color es de
            // alerta visual (warning si hay gastos, gray si no hay).
            Stat::make('Gastos del Mes', 'L. ' . number_format($netProfit['expenses'], 2))
                ->description($this->expensesDescription($netProfit['expenses']))
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color($netProfit['expenses'] > 0 ? 'warning' : 'gray'),

            // Utilidad Neta — la métrica que el cliente pidió.
            // Verde si positiva, rojo si negativa (pérdida).
            // El % es net_margin sobre revenue (no sobre gross_profit) —
            // así es comparable entre meses con distinto volumen de ventas.
            Stat::make('Utilidad Neta', 'L. ' . number_format($netProfit['net_profit'], 2))
                ->description($this->netProfitDescription(
                    $netProfit['net_profit'],
                    $netProfit['net_margin_percent']
                ))
                ->descriptionIcon($this->netProfitIcon($netProfit['net_profit']))
                ->color($this->netProfitColor($netProfit['net_profit'])),
        ];
    }

    private function expensesDescription(float $expenses): string
    {
        if ($expenses <= 0) {
            return 'Sin gastos registrados este mes';
        }

        return 'Incluye todos los rubros (deducibles y no)';
    }

    private function netProfitDescription(float $net, float $marginPercent): string
    {
        if ($net > 0) {
            return number_format($marginPercent, 1) . '% margen neto sobre ventas';
        }

        if ($net < 0) {
            return 'Pérdida — los gastos superan la ganancia bruta';
        }

        return 'Sin actividad financiera este mes';
    }

    private function netProfitIcon(float $net): string
    {
        return match (true) {
            $net > 0  => 'heroicon-m-check-circle',
            $net < 0  => 'heroicon-m-exclamation-triangle',
            default   => 'heroicon-m-minus',
        };
    }

    private function netProfitColor(float $net): string
    {
        return match (true) {
            $net > 0  => 'success',
            $net < 0  => 'danger',
            default   => 'gray',
        };
    }
}

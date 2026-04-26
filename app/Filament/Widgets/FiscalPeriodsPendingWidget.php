<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use App\Services\FiscalPeriods\FiscalPeriodService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Widget dashboard: alerta visible de períodos fiscales sin declarar.
 *
 * La vista SAR (Honduras) exige presentar el ISV mensual antes del día 10 del
 * mes siguiente. Si el contador olvida declarar acumula mora + recargo. Este
 * widget hace visible el atraso desde el dashboard sin forzar al usuario a
 * entrar al resource de Declaraciones.
 *
 * Niveles de urgencia:
 *   - 0 períodos  → stat verde "Al día"
 *   - 1 período   → warning amarillo "1 mes pendiente"
 *   - 2+ períodos → danger rojo "X meses pendientes" (atraso serio)
 *
 * El link del CTA apunta al listado de períodos para declarar desde ahí.
 *
 * Se oculta (canView) si el usuario no tiene permiso de ver períodos fiscales
 * — defense in depth: ya está filtrado por Shield pero evita consultas inútiles.
 */
class FiscalPeriodsPendingWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected ?string $pollingInterval = '300s';

    /**
     * Se inyecta via boot() — Livewire 3 soporta method injection en boot().
     * Propiedad protected para que NO se serialice entre requests (solo las
     * public lo hacen) — el container resuelve fresh cada render, manteniendo
     * el memo interno del singleton FiscalPeriodService.
     */
    protected FiscalPeriodService $fiscalPeriods;

    public function boot(FiscalPeriodService $fiscalPeriods): void
    {
        $this->fiscalPeriods = $fiscalPeriods;
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->can('ViewAny:FiscalPeriod');
    }

    protected function getStats(): array
    {
        $pendientes = $this->fiscalPeriods->listOverdue();
        $count = $pendientes->count();

        $label = match (true) {
            $count === 0 => 'Al día',
            $count === 1 => '1 mes pendiente',
            default => "{$count} meses pendientes",
        };

        $color = match (true) {
            $count === 0 => 'success',
            $count === 1 => 'warning',
            default => 'danger',
        };

        $description = $this->buildDescription($pendientes);

        $icon = match (true) {
            $count === 0 => 'heroicon-m-check-circle',
            $count === 1 => 'heroicon-m-clock',
            default => 'heroicon-m-exclamation-triangle',
        };

        return [
            Stat::make('Declaraciones ISV', $label)
                ->description($description)
                ->descriptionIcon($icon)
                ->color($color)
                ->url($count > 0 ? FiscalPeriodResource::getUrl('index') : null),
        ];
    }

    /**
     * Texto humano con los meses pendientes. Ej: "Marzo, Febrero 2026".
     * Si no hay pendientes muestra mensaje positivo.
     */
    private function buildDescription(\Illuminate\Support\Collection $pendientes): string
    {
        if ($pendientes->isEmpty()) {
            return 'Todos los períodos fiscales están declarados';
        }

        // Mostrar máximo 3 nombres, el resto como "+N".
        $labels = $pendientes->take(3)
            ->map(fn ($period) => $period->period_label)
            ->implode(' · ');

        $extra = $pendientes->count() - 3;

        return $extra > 0
            ? "{$labels} (+{$extra} más)"
            : $labels;
    }
}

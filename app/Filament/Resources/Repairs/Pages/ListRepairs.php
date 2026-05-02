<?php

namespace App\Filament\Resources\Repairs\Pages;

use App\Enums\RepairStatus;
use App\Filament\Resources\Repairs\RepairResource;
use App\Models\Repair;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListRepairs extends ListRecords
{
    protected static string $resource = RepairResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nueva reparación')
                ->icon('heroicon-o-plus'),
        ];
    }

    /**
     * Tabs de filtro por estado.
     *
     * IMPORTANTE Filament v4: el parámetro del closure de `modifyQueryUsing`
     * DEBE llamarse `$query` (no `$q` ni otro nombre). Filament resuelve los
     * argumentos del closure por NOMBRE de variable, no por posición. Si se
     * usa otro nombre, el closure recibe null y se cae con
     * "newQueryWithoutRelationships() on null" cuando Filament intenta usar
     * el Builder en applyFiltersToTableQuery.
     */
    public function getTabs(): array
    {
        $terminalStatuses = [
            RepairStatus::Entregada->value,
            RepairStatus::Rechazada->value,
            RepairStatus::Abandonada->value,
            RepairStatus::Anulada->value,
        ];

        return [
            'activas' => Tab::make('Activas')
                ->icon('heroicon-o-bolt')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotIn('status', $terminalStatuses))
                ->badge($this->cachedCount('activas', fn () => Repair::query()->whereNotIn('status', $terminalStatuses)->count()))
                ->badgeColor('warning'),

            'listas_entrega' => Tab::make('Listas para entregar')
                ->icon('heroicon-o-bell-alert')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', RepairStatus::ListoEntrega->value))
                ->badge($this->cachedCount(
                    'listas_entrega',
                    fn () => Repair::where('status', RepairStatus::ListoEntrega->value)->count(),
                ))
                ->badgeColor('success'),

            'cotizadas' => Tab::make('Cotizadas (esperan cliente)')
                ->icon('heroicon-o-document-text')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', RepairStatus::Cotizado->value))
                ->badge($this->cachedCount(
                    'cotizadas',
                    fn () => Repair::where('status', RepairStatus::Cotizado->value)->count(),
                ))
                ->badgeColor('info'),

            'en_reparacion' => Tab::make('En reparación')
                ->icon('heroicon-o-wrench-screwdriver')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', RepairStatus::EnReparacion->value))
                ->badge($this->cachedCount(
                    'en_reparacion',
                    fn () => Repair::where('status', RepairStatus::EnReparacion->value)->count(),
                ))
                ->badgeColor('primary'),

            'entregadas' => Tab::make('Entregadas')
                ->icon('heroicon-o-truck')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', RepairStatus::Entregada->value)),

            'canceladas' => Tab::make('Canceladas')
                ->icon('heroicon-o-x-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    RepairStatus::Rechazada->value,
                    RepairStatus::Anulada->value,
                    RepairStatus::Abandonada->value,
                ]))
                ->badge($this->cachedCount('canceladas', fn () => Repair::query()->whereIn('status', [
                    RepairStatus::Rechazada->value,
                    RepairStatus::Anulada->value,
                    RepairStatus::Abandonada->value,
                ])->count()))
                ->badgeColor('danger'),

            'todas' => Tab::make('Todas')
                ->icon('heroicon-o-list-bullet'),
        ];
    }

    /**
     * Tab activa por defecto al entrar al listado.
     * "Activas" es la vista útil para el día a día del staff.
     */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'activas';
    }

    /**
     * Cache de conteos por 60s.
     */
    private function cachedCount(string $key, \Closure $resolver): int
    {
        return cache()->remember(
            "repairs:list:tab_count:{$key}",
            60,
            $resolver,
        );
    }
}

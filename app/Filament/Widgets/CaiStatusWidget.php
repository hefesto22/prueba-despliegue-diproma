<?php

namespace App\Filament\Widgets;

use App\Authorization\CustomPermission;
use App\Filament\Resources\CaiRanges\CaiRangeResource;
use App\Models\CaiRange;
use App\Services\Alerts\CaiExpirationChecker;
use App\Services\Alerts\CaiRangeExhaustionChecker;
use App\Services\Alerts\Enums\CaiAlertSeverity;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * Widget dashboard: estado de los CAIs activos.
 *
 * Tres cards alineadas con los dos ejes de riesgo + un indicador global:
 *   1. "CAIs activos"        — cuántos hay activos en total, neutral/info.
 *   2. "Próximos a vencer"   — cuántos caen dentro del umbral de vigencia.
 *                              Color según mayor severidad presente.
 *   3. "Cercanos a agotarse" — cuántos están por agotar el rango.
 *                              Color según mayor severidad presente.
 *
 * Se oculta (canView) si el usuario NO tiene permiso `Manage:Cai`. Así los
 * cajeros no ven un widget que no les compete, y solo contador/admin lo
 * tienen visible desde el dashboard.
 *
 * pollingInterval 300s — mismo que FiscalPeriodsPendingWidget. Los cambios
 * en CAI no son de segundo a segundo, 5 minutos de fresh es suficiente y
 * evita hammering innecesario al DB desde pestañas abiertas todo el día.
 */
class CaiStatusWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '300s';

    /**
     * Se inyectan via boot() — Livewire 3 soporta method injection en boot().
     * Propiedades protected para que NO se serialicen entre requests.
     */
    protected CaiExpirationChecker $expirationChecker;

    protected CaiRangeExhaustionChecker $exhaustionChecker;

    public function boot(
        CaiExpirationChecker $expirationChecker,
        CaiRangeExhaustionChecker $exhaustionChecker,
    ): void {
        $this->expirationChecker = $expirationChecker;
        $this->exhaustionChecker = $exhaustionChecker;
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->can(CustomPermission::ManageCai->value);
    }

    protected function getStats(): array
    {
        // Contador simple de activos — una sola query indexada por is_active.
        $activosCount = CaiRange::query()->where('is_active', true)->count();

        /** @var Collection<int, \App\Services\Alerts\DTOs\CaiExpirationAlert> $expirationAlerts */
        $expirationAlerts = $this->expirationChecker->check();

        /** @var Collection<int, \App\Services\Alerts\DTOs\CaiRangeExhaustionAlert> $exhaustionAlerts */
        $exhaustionAlerts = $this->exhaustionChecker->check();

        return [
            $this->activosStat($activosCount),
            $this->expirationStat($expirationAlerts),
            $this->exhaustionStat($exhaustionAlerts),
        ];
    }

    private function activosStat(int $count): Stat
    {
        $label = match (true) {
            $count === 0 => 'Sin CAI activo',
            $count === 1 => '1 CAI activo',
            default => "{$count} CAIs activos",
        };

        return Stat::make('CAIs activos', $label)
            ->description('Rangos vigentes en uso')
            ->descriptionIcon('heroicon-m-document-check')
            // Danger si no hay ningún CAI activo — el sistema no puede emitir
            // facturas. Info en cualquier otro caso (estado normal).
            ->color($count === 0 ? 'danger' : 'info');
    }

    /**
     * @param  Collection<int, \App\Services\Alerts\DTOs\CaiExpirationAlert>  $alerts
     */
    private function expirationStat(Collection $alerts): Stat
    {
        $count = $alerts->count();

        if ($count === 0) {
            return Stat::make('Próximos a vencer', 'Ninguno')
                ->description('Todos los CAIs dentro de vigencia segura')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success');
        }

        $severity = $this->maxSeverity($alerts->map(fn ($a) => $a->severity));

        return Stat::make('Próximos a vencer', (string) $count)
            ->description($this->buildExpirationDescription($alerts))
            ->descriptionIcon('heroicon-m-clock')
            ->color($severity->filamentColor())
            ->url(CaiRangeResource::getUrl('index'));
    }

    /**
     * @param  Collection<int, \App\Services\Alerts\DTOs\CaiRangeExhaustionAlert>  $alerts
     */
    private function exhaustionStat(Collection $alerts): Stat
    {
        $count = $alerts->count();

        if ($count === 0) {
            return Stat::make('Cercanos a agotarse', 'Ninguno')
                ->description('Rangos con volumen suficiente')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success');
        }

        $severity = $this->maxSeverity($alerts->map(fn ($a) => $a->severity));

        return Stat::make('Cercanos a agotarse', (string) $count)
            ->description($this->buildExhaustionDescription($alerts))
            ->descriptionIcon('heroicon-m-chart-bar-square')
            ->color($severity->filamentColor())
            ->url(CaiRangeResource::getUrl('index'));
    }

    /**
     * Severidad máxima presente en la colección (Critical > Urgent > Info).
     *
     * @param  Collection<int, CaiAlertSeverity>  $severities
     */
    private function maxSeverity(Collection $severities): CaiAlertSeverity
    {
        return $severities
            ->sortByDesc(fn (CaiAlertSeverity $s) => $s->weight())
            ->first() ?? CaiAlertSeverity::Info;
    }

    /**
     * @param  Collection<int, \App\Services\Alerts\DTOs\CaiExpirationAlert>  $alerts
     */
    private function buildExpirationDescription(Collection $alerts): string
    {
        // Prioriza el que vence antes — ya viene ordenado ASC desde el checker.
        $proximo = $alerts->first();

        $base = "Más próximo: {$proximo->daysUntilExpiration} día"
            . ($proximo->daysUntilExpiration === 1 ? '' : 's');

        if ($alerts->contains(fn ($a) => ! $a->hasSuccessor)) {
            return $base . ' · ⚠️ hay sin sucesor';
        }

        return $base;
    }

    /**
     * @param  Collection<int, \App\Services\Alerts\DTOs\CaiRangeExhaustionAlert>  $alerts
     */
    private function buildExhaustionDescription(Collection $alerts): string
    {
        // El más próximo a agotarse = menor remaining.
        /** @var \App\Services\Alerts\DTOs\CaiRangeExhaustionAlert $menor */
        $menor = $alerts->sortBy('remaining')->first();

        $base = "Menor restante: {$menor->remaining} factura"
            . ($menor->remaining === 1 ? '' : 's');

        if ($alerts->contains(fn ($a) => ! $a->hasSuccessor)) {
            return $base . ' · ⚠️ hay sin sucesor';
        }

        return $base;
    }
}

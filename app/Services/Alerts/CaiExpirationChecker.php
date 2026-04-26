<?php

namespace App\Services\Alerts;

use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Services\Alerts\DTOs\CaiExpirationAlert;
use App\Services\Alerts\Enums\CaiAlertSeverity;
use Illuminate\Support\Collection;

/**
 * Detecta CAIs activos cercanos a su fecha de vencimiento.
 *
 * Reglas de severidad (con sucesor pre-registrado):
 *   - Días restantes ≤ umbral más estrecho       → Critical
 *   - Días restantes ≤ umbral intermedio         → Urgent
 *   - Días restantes ≤ umbral más amplio         → Info
 *
 * Sin sucesor pre-registrado: SIEMPRE Critical, sin importar cuántos días
 * queden. Esta es la regla "NoSuccessorCritical": aunque queden 28 días, si
 * el contador no ha solicitado el siguiente CAI al SAR el riesgo de quedarse
 * sin correlativo es real (el SAR puede tardar días en autorizar).
 *
 * Los umbrales vienen de CompanySetting::cai_expiration_warning_days_list
 * (default [30, 15, 7]). Si el accessor retorna lista vacía no se generan
 * alertas — semántica de "alertas desactivadas".
 */
final class CaiExpirationChecker
{
    public function __construct(
        private readonly CaiSuccessorResolver $successors,
    ) {}

    /**
     * Construye la colección de alertas de vencimiento.
     *
     * @return Collection<int, CaiExpirationAlert>
     */
    public function check(): Collection
    {
        $settings = CompanySetting::current();
        $warningDays = $settings->cai_expiration_warning_days_list;

        if (empty($warningDays)) {
            return collect();
        }

        $maxDays = max($warningDays);
        $today = now()->startOfDay();
        $cutoff = $today->copy()->addDays($maxDays);

        // CAIs activos cuyo vencimiento cae dentro del horizonte de aviso.
        // Filtramos también por not-expired: si ya venció, otro mecanismo
        // (failover de Fase 2) lo desactivará — esta alerta es preventiva.
        $activos = CaiRange::query()
            ->active()
            ->whereNotNull('expiration_date')
            ->whereDate('expiration_date', '>=', $today)
            ->whereDate('expiration_date', '<=', $cutoff)
            ->orderBy('expiration_date')
            ->get();

        if ($activos->isEmpty()) {
            return collect();
        }

        $sucesores = $this->successors->resolveFor($activos);

        return $activos->map(function (CaiRange $cai) use ($warningDays, $sucesores) {
            // diffInDays(false) puede dar negativo si la fecha ya pasó —
            // el filtro de query lo previene, pero clamp por seguridad.
            $days = max(0, (int) now()->startOfDay()->diffInDays($cai->expiration_date, false));

            $hasSuccessor = $sucesores->has($this->successors->keyFor($cai));
            $severity = $this->resolveSeverity($days, $warningDays, $hasSuccessor);

            return new CaiExpirationAlert(
                cai: $cai,
                daysUntilExpiration: $days,
                severity: $severity,
                hasSuccessor: $hasSuccessor,
            );
        })->values();
    }

    /**
     * Mapea (días restantes, lista de umbrales, hay sucesor) → severidad.
     *
     * Si no hay sucesor: Critical inmediato (regla "NoSuccessorCritical").
     *
     * Si hay sucesor: usa los umbrales como buckets ordenados ascendente.
     * Trabajar en ASC permite preguntar "¿días ≤ umbral_n?" desde el más
     * estrecho al más amplio, asignando la severidad mayor que aplique.
     *
     * @param  array<int, int>  $warningDaysDesc  Umbrales desc (ej: [30, 15, 7]).
     */
    private function resolveSeverity(int $days, array $warningDaysDesc, bool $hasSuccessor): CaiAlertSeverity
    {
        if (! $hasSuccessor) {
            return CaiAlertSeverity::Critical;
        }

        $asc = collect($warningDaysDesc)->sort()->values()->all();

        // [umbral más estrecho, umbral intermedio, umbral más amplio]
        // Ej con [7, 15, 30]:
        //   días ≤ 7  → Critical
        //   días ≤ 15 → Urgent
        //   días ≤ 30 → Info
        if (isset($asc[0]) && $days <= $asc[0]) {
            return CaiAlertSeverity::Critical;
        }

        if (isset($asc[1]) && $days <= $asc[1]) {
            return CaiAlertSeverity::Urgent;
        }

        return CaiAlertSeverity::Info;
    }
}

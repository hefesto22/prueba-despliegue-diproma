<?php

namespace App\Services\Alerts;

use App\Models\CaiRange;
use App\Services\Alerts\DTOs\CaiRangeExhaustionAlert;
use App\Services\Alerts\Enums\CaiAlertSeverity;
use Illuminate\Support\Collection;

/**
 * Detecta CAIs activos cuyo rango de correlativos se está acabando.
 *
 * El criterio de "cerca de agotarse" lo decide el modelo
 * (`CaiRange::isNearExhaustion()`), que ya combina los dos umbrales
 * configurables en CompanySetting (porcentaje + absoluto, lo que primero
 * ocurra). Aquí solo orquestamos: filtrar activos, aplicar el helper,
 * enriquecer con la información de sucesor y empaquetar en DTOs.
 *
 * Severidad:
 *   - Sin sucesor → Critical (regla "NoSuccessorCritical").
 *   - Con sucesor → Urgent (la operación puede continuar promoviéndolo).
 *
 * Por qué aquí solo hay dos tiers (no tres como en expiration): no tenemos
 * un eje natural para distinguir "Info" vs "Urgent" en agotamiento — el
 * propio threshold ya define cuándo alertar, y una vez disparado lo único
 * relevante es si hay relevo o no.
 */
final class CaiRangeExhaustionChecker
{
    public function __construct(
        private readonly CaiSuccessorResolver $successors,
    ) {}

    /**
     * @return Collection<int, CaiRangeExhaustionAlert>
     */
    public function check(): Collection
    {
        // Cargamos todos los activos no agotados — el filtro de "cerca de
        // agotarse" lo aplica isNearExhaustion() en PHP porque depende de
        // dos umbrales configurables y la lógica del helper combina ambos
        // (LO QUE PRIMERO se cumpla). Empujar esto a SQL duplicaría reglas.
        //
        // A escala de Diproma: ≤ 50 CaiRange en total, de los cuales pocos
        // están activos a la vez. Filtrar en PHP es O(N) trivial.
        $activos = CaiRange::query()
            ->active()
            ->whereColumn('current_number', '<', 'range_end')
            ->orderBy('document_type')
            ->orderBy('establishment_id')
            ->get();

        $nearExhaustion = $activos->filter(fn (CaiRange $c) => $c->isNearExhaustion());

        if ($nearExhaustion->isEmpty()) {
            return collect();
        }

        $sucesores = $this->successors->resolveFor($nearExhaustion);

        return $nearExhaustion->map(function (CaiRange $cai) use ($sucesores) {
            $hasSuccessor = $sucesores->has($this->successors->keyFor($cai));

            return new CaiRangeExhaustionAlert(
                cai: $cai,
                remaining: $cai->remaining,
                remainingPercentage: $cai->remaining_percentage,
                severity: $hasSuccessor ? CaiAlertSeverity::Urgent : CaiAlertSeverity::Critical,
                hasSuccessor: $hasSuccessor,
            );
        })->values();
    }
}

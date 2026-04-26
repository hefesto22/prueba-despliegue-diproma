<?php

namespace App\Services\Alerts;

use App\Models\CaiRange;
use App\Services\Alerts\Contracts\ResuelveSucesoresDeCai;
use Illuminate\Support\Collection;

/**
 * Resuelve en UNA sola query si existe "sucesor" pre-registrado para un
 * conjunto de CAIs activos.
 *
 * Sucesor = otro CaiRange que cumple TODAS estas condiciones respecto al
 * CAI activo en riesgo:
 *   - Mismo `document_type` (ej: otro 01)
 *   - Mismo `establishment_id` (incluyendo el caso null = centralizado)
 *   - `is_active = false` (está pre-registrado, listo para promover)
 *   - `expiration_date >= hoy` (no vencido antes de usarse)
 *   - `current_number < range_end` (aún tiene facturas disponibles)
 *
 * Por qué en batch: un listado de alertas toca potencialmente varios CAIs,
 * cada uno con su alcance. Un loop con un findSuccessor() por CAI es N+1 —
 * a escala de Diproma no se nota hoy, pero es deuda barata de evitar ahora.
 * La query usa OR agrupado por pares (doc_type, establishment_id), lo que
 * produce 1 query con N pares en vez de N queries con 1 par.
 */
final class CaiSuccessorResolver implements ResuelveSucesoresDeCai
{
    /**
     * Para cada CAI de la colección, determina si tiene sucesor listo.
     *
     * Retorna un mapa indexado por la key compuesta "doc_estab" → true.
     * Un CAI cuya key NO esté en el mapa implica que no tiene sucesor.
     *
     * @param  Collection<int, CaiRange>  $cais
     * @return Collection<string, bool>
     */
    public function resolveFor(Collection $cais): Collection
    {
        if ($cais->isEmpty()) {
            return collect();
        }

        // Pares únicos (document_type, establishment_id). Deduplicamos por
        // la key canónica para no enviar el mismo OR dos veces si hay
        // múltiples CAIs activos del mismo alcance (caso raro pero posible
        // transitoriamente antes de que la constraint DB lo detecte).
        $pairs = $cais
            ->map(fn (CaiRange $c) => [
                'document_type' => $c->document_type,
                'establishment_id' => $c->establishment_id,
            ])
            ->unique(fn (array $p) => $this->keyFromPair($p['document_type'], $p['establishment_id']))
            ->values();

        $today = now()->toDateString();

        $sucesores = CaiRange::query()
            ->where('is_active', false)
            ->where('expiration_date', '>=', $today)
            ->whereColumn('current_number', '<', 'range_end')
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as $p) {
                    $q->orWhere(function ($sub) use ($p) {
                        $sub->where('document_type', $p['document_type']);

                        if ($p['establishment_id'] === null) {
                            $sub->whereNull('establishment_id');
                        } else {
                            $sub->where('establishment_id', $p['establishment_id']);
                        }
                    });
                }
            })
            ->get();

        return $sucesores->mapWithKeys(
            fn (CaiRange $s) => [$this->keyFor($s) => true]
        );
    }

    /**
     * Key canónica de un CAI para indexar el mapa de sucesores.
     */
    public function keyFor(CaiRange $cai): string
    {
        return $this->keyFromPair($cai->document_type, $cai->establishment_id);
    }

    /**
     * Encuentra el CaiRange modelo que funcionaría como sucesor de `$cai`.
     *
     * Usado por `CaiFailoverService` (Fase 2) para promover automáticamente
     * un sucesor cuando el CAI actual está vencido o agotado. A diferencia
     * de `resolveFor()`, este método retorna el modelo completo para poder
     * llamar `->activate()` sobre él.
     *
     * Criterio de selección cuando hay más de un candidato válido:
     *   ORDER BY expiration_date DESC, (range_end - current_number) DESC
     *
     *   - `expiration_date DESC` primero: maximiza la ventana de uso útil
     *     del sucesor promovido (no promover uno que también está por vencer).
     *   - Luego mayor disponibilidad restante: si dos sucesores vencen el
     *     mismo día, preferir el de mayor rango disponible.
     *
     * Las 5 condiciones del sucesor válido son las mismas de `resolveFor()`:
     * mismo document_type, mismo establishment_id, is_active=false,
     * expiration_date >= hoy, current_number < range_end.
     *
     * Retorna null si no hay candidato válido — el caller debe tratarlo
     * como condición crítica (el POS quedará sin CAI activo).
     */
    public function findSuccessorFor(CaiRange $cai): ?CaiRange
    {
        $query = CaiRange::query()
            ->where('document_type', $cai->document_type)
            ->where('id', '!=', $cai->id)
            ->where('is_active', false)
            ->where('expiration_date', '>=', now()->toDateString())
            ->whereColumn('current_number', '<', 'range_end')
            ->orderByDesc('expiration_date')
            ->orderByRaw('(range_end - current_number) DESC');

        if ($cai->establishment_id === null) {
            $query->whereNull('establishment_id');
        } else {
            $query->where('establishment_id', $cai->establishment_id);
        }

        return $query->first();
    }

    private function keyFromPair(string $documentType, ?int $establishmentId): string
    {
        return $documentType.'_'.($establishmentId ?? 'null');
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Cai;

use App\Enums\DocumentType;
use App\Models\CaiRange;

/**
 * Responde a la pregunta "¿existe un CAI emisor disponible para este tipo de
 * documento y alcance?" sin ejecutar la emisión.
 *
 * Motivación: Filament oculta acciones con closures `visible()` que se
 * evalúan por cada fila del listado. Sin un memoizador centralizado, un
 * render de 50 facturas dispararía 50 queries idénticas a `cai_ranges` solo
 * para decidir si mostrar el botón "Emitir NC". Este service replica la
 * lógica de selección de los resolvers ({@see CorrelativoCentralizado} /
 * {@see CorrelativoPorSucursal}) pero en modo consulta — no avanza
 * correlativos, no lanza excepciones — y memoiza el resultado por el tiempo
 * de vida del request.
 *
 * **Registrar como singleton** en {@see \App\Providers\AppServiceProvider}
 * para que el memo sobreviva entre las N resoluciones que hace Filament del
 * closure. Sin singleton cada closure obtendría una instancia nueva y el
 * cache sería inútil — mismo patrón que FiscalPeriodService.
 *
 * Criterio de "CAI disponible":
 *   - `is_active = true`
 *   - `expiration_date >= hoy` (no vencido)
 *   - `current_number < range_end` (no agotado)
 *   - Alcance según `config('invoicing.mode')`:
 *       * centralizado  → cualquier CAI del tipo sirve (ignora establishment)
 *       * por_sucursal  → CAI debe estar vinculado al establishment dado
 *
 * Safe para uso como singleton: la única propiedad mutable es el memo, que
 * es read-through puro (nadie lo invalida desde afuera); métodos de escritura
 * NO existen.
 */
final class CaiAvailabilityService
{
    /**
     * Memo compartido por request. Claves:
     *   - modo centralizado : "{type}:any"
     *   - modo por sucursal : "{type}:{estId|null}"
     *
     * @var array<string, bool>
     */
    private array $cache = [];

    /**
     * ¿Hay al menos un CAI activo, no vencido y no agotado capaz de emitir
     * documentos del tipo solicitado para el alcance dado?
     *
     * En modo centralizado el `establishmentId` es irrelevante (la numeración
     * es una sola a nivel empresa) — los resultados se memoizan bajo una
     * sola clave `{type}:any` para que todas las facturas del listado
     * compartan una única query, independientemente de su sucursal origen.
     *
     * En modo por sucursal la emisión exige vínculo 1:1 entre CAI y
     * establecimiento; un `establishmentId = null` siempre retorna false
     * (no tiene alcance válido donde buscar).
     */
    public function hasActiveCaiFor(DocumentType $type, ?int $establishmentId = null): bool
    {
        $key = $this->cacheKey($type, $establishmentId);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->queryAvailability($type, $establishmentId);
    }

    /**
     * Limpia el memo. Uso exclusivo en tests — el service no expone
     * invalidación en runtime porque un CAI recién activado no invalida la
     * decisión tomada dentro del mismo HTTP request (la UI se refresca en
     * el próximo render).
     *
     * @internal
     */
    public function flushCache(): void
    {
        $this->cache = [];
    }

    private function cacheKey(DocumentType $type, ?int $establishmentId): string
    {
        // Modo centralizado: todas las sucursales comparten la misma
        // respuesta — memoizar bajo una sola clave evita que el memo crezca
        // con establishment_ids que no aportan información.
        if (config('invoicing.mode') !== 'por_sucursal') {
            return $type->value . ':any';
        }

        return $type->value . ':' . ($establishmentId ?? 'null');
    }

    private function queryAvailability(DocumentType $type, ?int $establishmentId): bool
    {
        $query = CaiRange::query()
            ->where('is_active', true)
            ->where('document_type', $type->value)
            ->where('expiration_date', '>=', now()->toDateString())
            // whereColumn: no agotado. Usa el mismo criterio que
            // CorrelativoCentralizado::siguiente (current_number < range_end)
            // para que la UI no prometa algo que el resolver rechazaría.
            ->whereColumn('current_number', '<', 'range_end');

        if (config('invoicing.mode') === 'por_sucursal') {
            // En modo por sucursal, el establishment es obligatorio —
            // sin él no existe alcance válido donde buscar un CAI emisor.
            if ($establishmentId === null) {
                return false;
            }

            $query->where('establishment_id', $establishmentId);
        }
        // En modo centralizado no filtramos por establishment: cualquier CAI
        // activo del tipo sirve (el resolver ignora establishment_id del CAI
        // al emitir).

        return $query->exists();
    }
}

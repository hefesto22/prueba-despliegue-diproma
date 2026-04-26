<?php

namespace App\Services\Alerts;

use App\Events\CaiFailoverExecuted;
use App\Models\CaiRange;
use App\Services\Alerts\Contracts\ResuelveSucesoresDeCai;
use App\Services\Alerts\DTOs\CaiFailoverReport;
use App\Services\Alerts\DTOs\CaiFailoverResult;
use App\Services\Invoicing\Exceptions\CaiSinSucesorException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Promueve automáticamente CAIs sucesores cuando el CAI activo queda
 * inutilizable.
 *
 * Un CAI activo "requiere failover" cuando se cumple cualquiera de estas dos
 * condiciones:
 *   - `expiration_date < hoy`           → vencido (SAR no permite emitir).
 *   - `current_number >= range_end`     → agotado (no hay correlativos libres).
 *
 * Para cada caso, el servicio:
 *   1. Busca un sucesor con `CaiSuccessorResolver::findSuccessorFor()`.
 *   2. Si existe → llama `->activate()` sobre el sucesor (ya garantiza
 *      transacción + lock + UNIQUE constraint contra carreras).
 *   3. Si no existe → registra entrada en `skippedNoSuccessor` del reporte
 *      (la notificación crítica la emite el Job orquestador).
 *
 * Decisión deliberada: NO se propaga ninguna excepción al caller. Un fallo
 * aislado en un CAI no debe detener la iteración sobre los demás. Los errores
 * inesperados se acumulan en `errors` con el `Throwable` original adjunto para
 * que el Job pueda loggearlos con stack trace completo.
 *
 * Complejidad:
 *   - 1 query para traer todos los CAIs en estado crítico.
 *   - 1 query adicional por cada candidato (findSuccessorFor).
 *   - N real ≤ 5 simultáneos en operación normal, así que el patrón "1 query
 *     por CAI" es aceptable y mantiene el código claro. Si el volumen creciera
 *     a decenas, valdría la pena migrar a una versión batch del resolver.
 */
final class CaiFailoverService
{
    public function __construct(
        private readonly ResuelveSucesoresDeCai $successors,
    ) {}

    /**
     * Ejecuta el failover sobre todos los CAIs activos en estado crítico.
     *
     * Idempotente: si no hay CAIs en estado crítico, retorna un reporte vacío
     * sin tocar la base de datos más allá del SELECT inicial.
     */
    public function executeFailover(): CaiFailoverReport
    {
        $caisCriticos = $this->loadCaisRequiringFailover();

        if ($caisCriticos->isEmpty()) {
            return CaiFailoverReport::empty();
        }

        $activated = collect();
        $skippedNoSuccessor = collect();
        $errors = collect();

        foreach ($caisCriticos as $cai) {
            try {
                $result = $this->attemptFailoverFor($cai);
                $activated->push($result);
            } catch (CaiSinSucesorException $e) {
                $skippedNoSuccessor->push([
                    'cai' => $cai,
                    'exception' => $e,
                ]);
            } catch (Throwable $e) {
                // Error inesperado — se loggea aquí para que aparezca en Horizon
                // aunque el caller decida no propagarlo. El Job lo verá también
                // a través del bucket `errors` para emitir alerta a oncall.
                Log::error('CaiFailoverService: error inesperado al promover sucesor', [
                    'cai_id' => $cai->id,
                    'document_type' => $cai->document_type,
                    'establishment_id' => $cai->establishment_id,
                    'exception' => $e,
                ]);

                $errors->push([
                    'cai' => $cai,
                    'exception' => $e,
                ]);
            }
        }

        return new CaiFailoverReport(
            activated: $activated,
            skippedNoSuccessor: $skippedNoSuccessor,
            errors: $errors,
        );
    }

    /**
     * Intenta promover un sucesor para un CAI específico.
     *
     * @throws CaiSinSucesorException Si no existe sucesor pre-registrado válido.
     */
    private function attemptFailoverFor(CaiRange $cai): CaiFailoverResult
    {
        // Computamos la razón ANTES de cualquier refresh: el motivo del
        // failover es atributo inmutable de este evento, no debe cambiar
        // después de que activate() desactive al viejo.
        $reason = $this->resolveReason($cai);

        $sucesor = $this->successors->findSuccessorFor($cai);

        if ($sucesor === null) {
            throw new CaiSinSucesorException(
                caiRangeId: $cai->id,
                cai: $cai->cai,
                documentType: $cai->document_type,
                establishmentId: $cai->establishment_id,
                reason: $reason,
            );
        }

        // activate() es transaccional + usa lockForUpdate + cuenta con la
        // UNIQUE constraint `uniq_active_cai_per_doc_estab` como red de
        // seguridad ante carreras. No necesitamos envolver nada acá.
        $sucesor->activate();

        // Refrescamos el CAI viejo para reflejar `is_active=false` que
        // activate() le aplicó al desactivar los del mismo alcance.
        $cai->refresh();
        $sucesor->refresh();

        $result = new CaiFailoverResult(
            oldCai: $cai,
            newCai: $sucesor,
            reason: $reason,
        );

        // Disparamos el evento de dominio: los listeners (auditoría en
        // activity_log, notificaciones informativas futuras) reaccionan sin
        // que este Service conozca sus detalles. El listener va por cola
        // (ShouldQueue) para no contaminar el reporte si falla la auditoría.
        CaiFailoverExecuted::dispatch($result);

        return $result;
    }

    /**
     * Carga los CAIs activos que requieren failover en una sola query.
     *
     * Criterio:
     *   - is_active = true (solo los que están en uso hoy).
     *   - expiration_date < hoy  OR  current_number >= range_end
     *     (vencido o agotado — uno solo basta para inutilizarlo).
     */
    private function loadCaisRequiringFailover(): \Illuminate\Database\Eloquent\Collection
    {
        $today = now()->toDateString();

        return CaiRange::query()
            ->where('is_active', true)
            ->where(function ($q) use ($today) {
                $q->where('expiration_date', '<', $today)
                    ->orWhereColumn('current_number', '>=', 'range_end');
            })
            ->orderBy('document_type')
            ->orderBy('establishment_id')
            ->get();
    }

    /**
     * Determina la razón por la que un CAI está en estado crítico.
     *
     * Si está vencido Y agotado, la razón "vencido" tiene precedencia: una
     * decisión arbitraria pero consistente para reportes y auditoría.
     */
    private function resolveReason(CaiRange $cai): string
    {
        if ($cai->expiration_date->isPast()) {
            return CaiSinSucesorException::REASON_EXPIRED;
        }

        return CaiSinSucesorException::REASON_EXHAUSTED;
    }
}

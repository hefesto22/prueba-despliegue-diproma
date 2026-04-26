<?php

namespace App\Jobs;

use App\Authorization\CustomPermission;
use App\Models\User;
use App\Notifications\CaiFailoverFailedNotification;
use App\Services\Alerts\CaiAlertRecipientResolver;
use App\Services\Alerts\CaiFailoverService;
use App\Services\Alerts\DTOs\CaiFailoverReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job diario: ejecuta el mecanismo de failover automático de CAIs.
 *
 * Este Job es el orquestador del módulo de failover (Fase 2). Su
 * responsabilidad es coordinar tres cosas — no implementar ninguna de ellas:
 *
 *   1. Disparar CaiFailoverService::executeFailover() que hace el trabajo
 *      real de detectar CAIs inutilizables y promover sucesores.
 *   2. Observar el CaiFailoverReport resultante y loguear el desenlace en
 *      cada uno de sus tres buckets (activated, skippedNoSuccessor, errors).
 *   3. Emitir CaiFailoverFailedNotification a usuarios con permiso Manage:Cai
 *      cuando haya CAIs que no pudieron fallar sobre (bucket skippedNoSuccessor).
 *
 * Scheduling (routes/console.php): dailyAt('08:03') — corre DESPUÉS del job
 * de períodos fiscales (08:00) y ANTES del job de alertas preventivas de CAI
 * (08:05). Este orden es deliberado:
 *
 *   - Si un CAI está vencido al iniciar el día, queremos promover su sucesor
 *     ANTES de que las alertas preventivas lo reporten como "vencido" — tras
 *     el failover ya estará inactivo y otro CAI activo estará en su lugar.
 *   - Colocarlo después de las alertas generaría emails contradictorios:
 *     primero "CAI X vencido", luego "sucesor promovido" — ruido innecesario.
 *
 * Idempotencia diaria: Cache::add() con key distinta a la de SendCaiAlertsJob
 * para no colisionar. Ambos jobs corren el mismo día pero cada uno tiene su
 * propia garantía de ejecución única. Si Horizon reintenta o el scheduler
 * dispara dos veces, el segundo intento detecta el cache hit y se salta.
 *
 * Manejo de fallos: tres vías de reporte según el bucket del reporte:
 *   - activated       → Log::info con lista de CAIs promovidos (para auditoría
 *                       operacional y observabilidad en Horizon).
 *   - skippedNoSuccessor → CaiFailoverFailedNotification (database + mail) a
 *                          usuarios con Manage:Cai. Es CRÍTICO: el POS está
 *                          bloqueado hasta que registren un CAI manualmente.
 *   - errors          → Log::error con stack trace de cada excepción inesperada.
 *                       NO se notifica a usuarios finales porque un error
 *                       inesperado es un bug, no algo accionable por el negocio.
 *                       Oncall lo ve en Horizon + logs.
 *
 * Degradación silenciosa:
 *   - Report vacío (no hay CAIs en estado crítico) → Log::info y termina.
 *   - Hay skippedNoSuccessor pero ningún destinatario con Manage:Cai →
 *     Log::warning para que oncall lo note (el seeder de permisos debería
 *     haberse corrido; si no hay nadie con el permiso, es un mis-deploy).
 */
class ExecuteCaiFailoverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function handle(
        CaiFailoverService $failoverService,
        CaiAlertRecipientResolver $recipientResolver,
    ): void {
        // Guard idempotencia: una ejecución efectiva por día.
        // Key distinta de 'cai-alerts-sent' para que ambos jobs puedan correr
        // el mismo día sin interferir.
        $cacheKey = 'cai-failover-executed:'.now()->toDateString();
        $firstRunToday = Cache::add($cacheKey, true, now()->endOfDay());

        if (! $firstRunToday) {
            Log::info('ExecuteCaiFailoverJob skipped: already ran today.');

            return;
        }

        $report = $failoverService->executeFailover();

        if ($report->totalProcessed() === 0) {
            Log::info('ExecuteCaiFailoverJob: sin CAIs en estado crítico, no se hizo nada.');

            return;
        }

        $this->reportActivated($report);
        $this->reportErrors($report);
        $this->notifyOnFailures($report, $recipientResolver);

        Log::info('ExecuteCaiFailoverJob: ciclo de failover completado.', [
            'activated_count' => $report->activated->count(),
            'skipped_no_successor_count' => $report->skippedNoSuccessor->count(),
            'errors_count' => $report->errors->count(),
        ]);
    }

    /**
     * Loguea los CAIs promovidos exitosamente.
     *
     * El activity log detallado lo emite LogCaiFailoverActivity (listener de
     * CaiFailoverExecuted). Aquí solo dejamos una línea agregada para que
     * Horizon / logs muestren de un vistazo qué pasó en el job.
     */
    private function reportActivated(CaiFailoverReport $report): void
    {
        if ($report->activated->isEmpty()) {
            return;
        }

        $summary = $report->activated->map(fn ($result) => sprintf(
            'CAI %s (%s) → sucesor %s [motivo: %s]',
            $result->oldCai->cai,
            $result->oldCai->document_type,
            $result->newCai->cai,
            $result->reason,
        ))->implode(' · ');

        Log::info('ExecuteCaiFailoverJob: failovers ejecutados exitosamente.', [
            'count' => $report->activated->count(),
            'detail' => $summary,
        ]);
    }

    /**
     * Loguea errores inesperados (bugs, no casos de negocio esperados).
     *
     * Cada entrada lleva el Throwable completo para que el handler de logging
     * (Bugsnag / Sentry / stderr) capture stack trace. NO se notifica a los
     * usuarios finales: un error inesperado no es accionable por el negocio,
     * es responsabilidad de oncall diagnosticarlo.
     */
    private function reportErrors(CaiFailoverReport $report): void
    {
        if ($report->errors->isEmpty()) {
            return;
        }

        foreach ($report->errors as $entry) {
            /** @var \App\Models\CaiRange $cai */
            $cai = $entry['cai'];
            /** @var Throwable $exception */
            $exception = $entry['exception'];

            Log::error('ExecuteCaiFailoverJob: error inesperado procesando CAI.', [
                'cai_id' => $cai->id,
                'cai' => $cai->cai,
                'document_type' => $cai->document_type,
                'establishment_id' => $cai->establishment_id,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Envía notificación crítica a usuarios con Manage:Cai cuando hay CAIs
     * que quedaron bloqueados sin sucesor disponible.
     *
     * El bucket `skippedNoSuccessor` es el único que amerita ping a humanos:
     * solo ellos pueden resolverlo (registrando un CAI nuevo en Administración).
     *
     * La política de destinatarios vive en CaiAlertRecipientResolver — punto
     * único compartido con SendCaiAlertsJob para que cualquier cambio de
     * política aplique a todas las alertas CAI sin duplicar lógica.
     */
    private function notifyOnFailures(CaiFailoverReport $report, CaiAlertRecipientResolver $recipientResolver): void
    {
        if ($report->skippedNoSuccessor->isEmpty()) {
            return;
        }

        $recipients = $recipientResolver->resolve();

        if ($recipients->isEmpty()) {
            Log::warning(
                'ExecuteCaiFailoverJob: hay CAIs bloqueados sin sucesor pero ningún usuario '
                .'activo con permiso '.CustomPermission::ManageCai->value.'. '
                .'Correr CustomPermissionsSeeder y verificar asignación de roles.',
                [
                    'skipped_no_successor_count' => $report->skippedNoSuccessor->count(),
                ]
            );

            return;
        }

        $notification = new CaiFailoverFailedNotification($report->skippedNoSuccessor);
        $recipients->each(fn (User $user) => $user->notify($notification));
    }
}

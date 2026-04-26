<?php

namespace App\Jobs;

use App\Authorization\CustomPermission;
use App\Models\User;
use App\Notifications\CaiExpiringNotification;
use App\Notifications\CaiRangeExhaustingNotification;
use App\Services\Alerts\CaiAlertRecipientResolver;
use App\Services\Alerts\CaiExpirationChecker;
use App\Services\Alerts\CaiRangeExhaustionChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Job diario: notifica a usuarios con permiso `Manage:Cai` cuando hay CAIs
 * activos próximos a vencer o cercanos a agotar su rango.
 *
 * Scheduling: `dailyAt('08:05')` en routes/console.php — corremos 5 minutos
 * después del job de períodos fiscales para que ambas alertas lleguen
 * juntas al inicio del día laboral, pero sin solaparse en ejecución ni
 * competir por la misma cache slot.
 *
 * Idempotencia diaria: Cache::add() con key por día asegura que aunque el
 * scheduler dispare dos veces (retry, DST, restart de Horizon), solo se
 * envía UNA notificación por usuario por día. La idempotencia NO depende
 * del estado de la BD — eso sería frágil si se limpian notifications.
 *
 * Destinatarios: usuarios ACTIVOS con permiso `Manage:Cai`. Se excluyen
 * usuarios inactivos para no seguir notificando a cuentas desactivadas, y
 * usuarios sin el permiso custom para no inundar a cajeros con alertas que
 * no les corresponden gestionar.
 *
 * Degradación silenciosa: si no hay alertas en ninguno de los dos ejes, el
 * job loguea "sin alertas" y termina sin enviar nada. Si el permiso no
 * existe (seeder no ejecutado), loguea warning y continúa sin destinatarios.
 *
 * Dos notificaciones distintas (no una combinada): expiración y agotamiento
 * son problemas con remediaciones diferentes — agrupar confunde. El Job
 * solo dispara cada notificación si la lista correspondiente tiene alertas.
 */
class SendCaiAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function handle(
        CaiExpirationChecker $expirationChecker,
        CaiRangeExhaustionChecker $exhaustionChecker,
        CaiAlertRecipientResolver $recipientResolver,
    ): void {
        // Guard idempotencia: una ejecución efectiva por día.
        $cacheKey = 'cai-alerts-sent:' . now()->toDateString();
        $firstRunToday = Cache::add($cacheKey, true, now()->endOfDay());

        if (! $firstRunToday) {
            Log::info('SendCaiAlertsJob skipped: already ran today.');
            return;
        }

        $expirationAlerts = $expirationChecker->check();
        $exhaustionAlerts = $exhaustionChecker->check();

        if ($expirationAlerts->isEmpty() && $exhaustionAlerts->isEmpty()) {
            Log::info('SendCaiAlertsJob: sin alertas de CAI pendientes, no se envía nada.');
            return;
        }

        $recipients = $recipientResolver->resolve();

        if ($recipients->isEmpty()) {
            Log::warning('SendCaiAlertsJob: hay alertas CAI pero ningún usuario activo con permiso ' . CustomPermission::ManageCai->value . '.', [
                'expiration_count' => $expirationAlerts->count(),
                'exhaustion_count' => $exhaustionAlerts->count(),
            ]);
            return;
        }

        if ($expirationAlerts->isNotEmpty()) {
            $notification = new CaiExpiringNotification($expirationAlerts);
            $recipients->each(fn (User $user) => $user->notify($notification));
        }

        if ($exhaustionAlerts->isNotEmpty()) {
            $notification = new CaiRangeExhaustingNotification($exhaustionAlerts);
            $recipients->each(fn (User $user) => $user->notify($notification));
        }

        Log::info('SendCaiAlertsJob: alertas enviadas.', [
            'expiration_count' => $expirationAlerts->count(),
            'exhaustion_count' => $exhaustionAlerts->count(),
            'recipients_count' => $recipients->count(),
        ]);
    }
}

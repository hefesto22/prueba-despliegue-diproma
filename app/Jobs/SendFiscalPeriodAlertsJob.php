<?php

namespace App\Jobs;

use App\Authorization\CustomPermission;
use App\Models\User;
use App\Notifications\FiscalPeriodsPendingNotification;
use App\Services\FiscalPeriods\FiscalPeriodService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

/**
 * Job diario: notifica a usuarios que pueden declarar ISV cuando hay
 * períodos fiscales sin declarar.
 *
 * Scheduling: `daily()->at('08:00')` en routes/console.php — la hora está
 * pensada para que el contador vea la alerta al inicio del día laboral,
 * no a las 00:00 cuando no le sirve.
 *
 * Idempotencia diaria: Cache::add() con key por día asegura que aunque el
 * scheduler dispare dos veces (ej: retry después de error), solo se envía
 * una notificación por usuario por día. La idempotencia NO depende del
 * estado de la BD (eso sería frágil si se limpian notifications).
 *
 * Destinatarios: usuarios ACTIVOS con permiso `Declare:FiscalPeriod`.
 * Se excluyen usuarios inactivos para no seguir notificando a cuentas
 * desactivadas, y usuarios sin el permiso custom para no inundar a todo
 * el equipo con alertas que no les corresponden.
 *
 * Degradación silenciosa: si `fiscal_period_start` no está configurado,
 * `listOverdue()` retorna vacío y el job loguea "skipped" sin fallar.
 */
class SendFiscalPeriodAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function handle(FiscalPeriodService $service): void
    {
        // Guard idempotencia: una ejecución efectiva por día.
        $cacheKey = 'fiscal-period-alerts-sent:' . now()->toDateString();
        $firstRunToday = Cache::add($cacheKey, true, now()->endOfDay());

        if (! $firstRunToday) {
            Log::info('SendFiscalPeriodAlertsJob skipped: already ran today.');
            return;
        }

        // Poblar registros de meses vencidos sin actividad ANTES de consultar.
        // El scheduler es la fuente única de verdad que convierte "mes calendario
        // vencido" en "FiscalPeriod registrado": widget y badge del admin solo
        // leen, nunca crean. Si este job no corriera, los meses sin facturas
        // no aparecerían en la lista de pendientes — inaceptable para SAR que
        // exige declaración cero incluso en meses sin operaciones.
        $service->ensureOverduePeriodsExist();

        $pendientes = $service->listOverdue();

        if ($pendientes->isEmpty()) {
            Log::info('SendFiscalPeriodAlertsJob: sin períodos pendientes, no se envía nada.');
            return;
        }

        $recipients = $this->resolveRecipients();

        if ($recipients->isEmpty()) {
            Log::warning('SendFiscalPeriodAlertsJob: hay períodos pendientes pero ningún usuario activo con permiso ' . CustomPermission::DeclareFiscalPeriod->value . '.', [
                'pending_count' => $pendientes->count(),
            ]);
            return;
        }

        $notification = new FiscalPeriodsPendingNotification($pendientes);

        $recipients->each(fn (User $user) => $user->notify($notification));

        Log::info('SendFiscalPeriodAlertsJob: alertas enviadas.', [
            'pending_count' => $pendientes->count(),
            'recipients_count' => $recipients->count(),
        ]);
    }

    /**
     * Usuarios activos con permiso de declarar.
     *
     * Resolución: buscar el permission, obtener sus roles, obtener usuarios
     * de esos roles + usuarios con el permiso directo. Incluye super_admin
     * automáticamente (Gate::before en AuthServiceProvider de Shield).
     *
     * Nombre del permiso leído del enum `CustomPermission::DeclareFiscalPeriod`
     * — fuente de verdad. Evita magic strings que podrían desincronizarse
     * del seeder.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function resolveRecipients(): \Illuminate\Support\Collection
    {
        $permissionName = CustomPermission::DeclareFiscalPeriod->value;

        $permission = Permission::where('name', $permissionName)->first();

        if ($permission === null) {
            Log::warning("SendFiscalPeriodAlertsJob: permiso {$permissionName} no existe. Correr CustomPermissionsSeeder.");
            return collect();
        }

        // Usuarios con el permiso vía rol o directamente asignado.
        $viaRoles = User::active()
            ->whereHas('roles.permissions', fn ($q) => $q->where('name', $permissionName))
            ->get();

        $direct = User::active()
            ->whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
            ->get();

        // Super admins siempre reciben (Shield les da Gate::before true).
        $superAdminRole = \BezhanSalleh\FilamentShield\Support\Utils::getSuperAdminName();
        $superAdmins = User::active()
            ->whereHas('roles', fn ($q) => $q->where('name', $superAdminRole))
            ->get();

        return $viaRoles->concat($direct)->concat($superAdmins)->unique('id')->values();
    }
}

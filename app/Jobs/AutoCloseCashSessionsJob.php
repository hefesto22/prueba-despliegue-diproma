<?php

namespace App\Jobs;

use App\Exceptions\Cash\MovimientoEnSesionCerradaException;
use App\Models\CashSession;
use App\Models\User;
use App\Notifications\CashSessionAutoClosedNotification;
use App\Services\Cash\CashSessionService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job programado: cierra automáticamente todas las sesiones de caja que
 * quedaron abiertas al final del día operativo.
 *
 * Motivación operativa:
 *   El cajero a veces olvida cerrar la caja al final del turno. Sin este job,
 *   la sesión queda abierta durante la noche y el día siguiente — los movimientos
 *   acumulados rompen el cuadre por turno y dificultan auditoría. El auto-cierre
 *   forzado a una hora razonable (default 21:00 HN) garantiza un ciclo diario
 *   limpio. La conciliación posterior (con conteo físico real) la hace el cajero
 *   o un admin via `CashSessionService::reconcile()`.
 *
 * Scheduling:
 *   `dailyAt(config('cash.auto_close.hour'))->timezone('America/Tegucigalpa')`
 *   en routes/console.php. Default 21:00 HN, configurable por env CASH_AUTO_CLOSE_HOUR.
 *   El switch global `config('cash.auto_close.enabled')` permite desactivar el
 *   mecanismo en escenarios excepcionales (auditorías, jornadas extendidas
 *   autorizadas) sin tocar el scheduler.
 *
 * Idempotencia:
 *   No usa Cache::add() como guard global porque el mecanismo NATURAL de
 *   idempotencia es la propia sesión: una vez cerrada, intentar cerrarla de
 *   nuevo lanza `MovimientoEnSesionCerradaException` que el job captura por
 *   sesión y sigue. Esto significa que un retry o doble disparo no produce
 *   estado inconsistente — solo loguea "ya cerrada" para esas sesiones.
 *
 *   La consecuencia es que SÍ podría enviarse notificación duplicada si una
 *   sesión se cerrara entre el fetch y el `closeBySystem`. En la práctica
 *   esto es casi imposible (el job corre fuera de horario operativo) y el
 *   ruido de una notificación duplicada es preferible a un guard de cache
 *   que sea fuente de verdad alterna al estado de la BD.
 *
 * Resiliencia:
 *   Cada sesión se cierra en su propio try/catch. Si falla una, el job sigue
 *   con las demás. El error se loguea con el ID de la sesión para diagnóstico
 *   posterior. El job nunca aborta a media ejecución — tries=3 protege solo
 *   contra fallas de infra (DB caída momentánea), no contra errores de dominio
 *   por sesión.
 *
 * Notificaciones:
 *   Por cada sesión cerrada con éxito se notifica:
 *     - Al cajero que la abrió (afectado directo, debe conciliar).
 *     - A admins activos de cualquier sucursal (visibilidad operacional —
 *       en single-tenant todos los admins ven todo, multi-tenant ya no aplica).
 *   La notificación apunta al view de la sesión para iniciar la conciliación.
 */
class AutoCloseCashSessionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function handle(CashSessionService $sessions): void
    {
        if (! (bool) config('cash.auto_close.enabled', true)) {
            Log::info('AutoCloseCashSessionsJob: auto-cierre deshabilitado por config.');
            return;
        }

        // Cargamos relaciones necesarias para la notificación de una sola vez.
        // Eager `establishment` y `openedBy` evita N+1 al iterar.
        /** @var Collection<int, CashSession> $openSessions */
        $openSessions = CashSession::query()
            ->open()
            ->with(['establishment:id,name', 'openedBy:id,name,email,email_verified_at'])
            ->get();

        if ($openSessions->isEmpty()) {
            Log::info('AutoCloseCashSessionsJob: no había sesiones abiertas, nada que cerrar.');
            return;
        }

        $closed = 0;
        $alreadyClosed = 0;
        $errors = 0;

        // Resolvemos admins UNA sola vez antes del loop — no cambian durante
        // la ejecución del job y resolverlos por sesión sería N queries inútiles.
        $admins = $this->activeAdmins();

        foreach ($openSessions as $session) {
            try {
                $closedSession = $sessions->closeBySystem($session);
                $closed++;
                $this->notifyRecipients($closedSession, $session, $admins);
            } catch (MovimientoEnSesionCerradaException $e) {
                // Carrera benigna: alguien (cierre manual o retry previo) ya
                // cerró esta sesión. Seguimos con la siguiente sin alarma.
                $alreadyClosed++;
                Log::info('AutoCloseCashSessionsJob: sesión ya cerrada, omitida.', [
                    'session_id' => $session->id,
                ]);
            } catch (Throwable $e) {
                // Error inesperado en una sesión particular — no debe abortar
                // el job entero. Loguear con contexto suficiente para investigar.
                $errors++;
                Log::error('AutoCloseCashSessionsJob: error cerrando sesión.', [
                    'session_id'      => $session->id,
                    'establishment_id'=> $session->establishment_id,
                    'exception'       => $e::class,
                    'message'         => $e->getMessage(),
                ]);
            }
        }

        Log::info('AutoCloseCashSessionsJob: ejecución completada.', [
            'total_open'      => $openSessions->count(),
            'closed'          => $closed,
            'already_closed'  => $alreadyClosed,
            'errors'          => $errors,
        ]);
    }

    /**
     * Notificar al cajero responsable + a todos los admins activos.
     *
     * Recibe:
     *   - $closedSession: estado FRESCO post-cierre (con expected_closing_amount
     *     calculado), que es el que muestra la notificación.
     *   - $originalSession: instancia con relaciones eager `establishment` y
     *     `openedBy` ya cargadas — usadas para evitar otra query.
     */
    private function notifyRecipients(
        CashSession $closedSession,
        CashSession $originalSession,
        Collection $admins,
    ): void {
        $establishmentName = $originalSession->establishment?->name ?? "Sucursal #{$originalSession->establishment_id}";

        $notification = new CashSessionAutoClosedNotification(
            session: $closedSession,
            establishmentName: $establishmentName,
        );

        // El cajero que abrió la sesión — siempre destinatario primario.
        // openedBy puede ser null si el FK está roto (no debería pasar, pero
        // defendemos sin caer al loguear y seguir).
        $cashier = $originalSession->openedBy;

        if ($cashier !== null) {
            $cashier->notify($notification);
        } else {
            Log::warning('AutoCloseCashSessionsJob: sesión sin openedBy resolvible.', [
                'session_id'        => $originalSession->id,
                'opened_by_user_id' => $originalSession->opened_by_user_id,
            ]);
        }

        // Admins: visibilidad operacional. Excluimos al cajero si por casualidad
        // tiene rol admin (single user → single notification).
        $admins
            ->reject(fn (User $u) => $cashier !== null && $u->id === $cashier->id)
            ->each(fn (User $u) => $u->notify($notification));
    }

    /**
     * Admins activos del sistema (super_admin + admin).
     *
     * Excluye is_active=false y excluye al system user automáticamente porque
     * éste no tiene roles asignados (la cláusula `whereHas roles` ya lo deja fuera).
     *
     * Single-tenant: todos los admins reciben todas las notificaciones de
     * auto-cierre. Cuando el proyecto evolucione a multi-tenant, esto cambia
     * a "admins de la sucursal de la sesión" — pero esa decisión está diferida
     * 2-3 años por decisión del negocio.
     *
     * @return Collection<int, User>
     */
    private function activeAdmins(): Collection
    {
        $superAdminRole = Utils::getSuperAdminName();

        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [$superAdminRole, 'admin']))
            ->where('is_active', true)
            ->get();
    }
}

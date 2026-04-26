<?php

use App\Jobs\AutoCloseCashSessionsJob;
use App\Jobs\ExecuteCaiFailoverJob;
use App\Jobs\SendCaiAlertsJob;
use App\Jobs\SendFiscalPeriodAlertsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Scheduler ───────────────────────────────────────────────

/*
 * Alerta diaria de períodos fiscales sin declarar.
 *
 * Corre todos los días a las 08:00 hora HN (America/Tegucigalpa). El job
 * internamente valida idempotencia por día mediante Cache, así que doble
 * disparo (retry, cambio de DST, etc.) no produce notificaciones duplicadas.
 *
 * Timezone explícito: el negocio opera en HN. Sin esto el scheduler usa
 * APP_TIMEZONE (UTC por default) y el job correría a las 02:00 AM HN —
 * justo cuando los usuarios duermen y antes de que los datos del día estén
 * listos para reportar.
 *
 * withoutOverlapping evita que si el job aún está ejecutándose por alguna
 * razón, el scheduler no lance una segunda instancia concurrente.
 *
 * onOneServer se deja por compatibilidad con cualquier futuro scale-out de
 * workers. Hoy corre en un solo servidor pero activarlo ahora es gratis.
 */
Schedule::job(new SendFiscalPeriodAlertsJob)
    ->dailyAt('08:00')
    ->timezone('America/Tegucigalpa')
    ->name('fiscal-period-alerts')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Failover automático de CAIs vencidos o agotados.
 *
 * Corre a las 08:03, DESPUÉS del job de períodos fiscales (08:00) y ANTES
 * del job de alertas preventivas de CAI (08:05). Este orden es deliberado:
 *
 *   - Si un CAI amaneció vencido o agotado, queremos promover su sucesor
 *     ANTES de que las alertas preventivas lo reporten — tras el failover
 *     el CAI viejo ya estará inactivo y otro activo estará en su lugar.
 *   - Invertir el orden generaría emails contradictorios: primero "CAI X
 *     vencido", luego "sucesor promovido" — ruido innecesario para el usuario.
 *
 * Si algún CAI queda sin sucesor disponible (bloqueado), el job emite
 * CaiFailoverFailedNotification (crítica) a los usuarios con Manage:Cai.
 * Idempotencia diaria garantizada por Cache::add() con key distinta a las
 * de los otros dos jobs del día, para no colisionar.
 *
 * Mismas garantías operacionales que los otros jobs diarios:
 *   - withoutOverlapping → no se lanza una segunda instancia si la previa
 *                          aún está ejecutándose.
 *   - onOneServer        → futuro-proof para scale-out de workers.
 */
Schedule::job(new ExecuteCaiFailoverJob)
    ->dailyAt('08:03')
    ->timezone('America/Tegucigalpa')
    ->name('cai-failover')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Alerta diaria de CAIs próximos a vencer o cercanos a agotarse.
 *
 * Corre 5 minutos después del job de períodos fiscales para que ambas
 * alertas lleguen juntas al inicio del día laboral, sin solaparse en
 * ejecución ni competir por la misma cache slot.
 *
 * El job evalúa dos ejes independientes (vigencia temporal + volumen de
 * correlativos) y dispara hasta dos notificaciones distintas según haya
 * alertas en cada eje. Idempotencia diaria garantizada por Cache::add()
 * con key por día (ver SendCaiAlertsJob::handle).
 *
 * Mismas garantías operacionales que el job anterior:
 *   - withoutOverlapping → no se lanza una segunda instancia si la previa
 *                          aún está ejecutándose.
 *   - onOneServer        → futuro-proof para scale-out de workers.
 */
Schedule::job(new SendCaiAlertsJob)
    ->dailyAt('08:05')
    ->timezone('America/Tegucigalpa')
    ->name('cai-alerts')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Auto-cierre nocturno de sesiones de caja olvidadas.
 *
 * Corre todos los días a la hora configurada en config/cash.php (default 21:00 HN).
 * Cierra cualquier sesión que haya quedado abierta vía CashSessionService::closeBySystem,
 * que NO calcula descuadre (no contó plata) y marca requires_reconciliation=true.
 * El cajero/admin debe completar la conciliación al regresar (acción ReconcileCashSessionAction).
 *
 * Hora dinámica: leemos `config('cash.auto_close.hour')` al cargar las rutas.
 * Cambiar la hora en runtime requiere `php artisan config:clear` + reload del
 * scheduler — comportamiento esperado para un parámetro operativo de este nivel.
 *
 * Timezone explícito: el negocio opera en America/Tegucigalpa. Sin esto el
 * scheduler usa APP_TIMEZONE (UTC por default), lo que correría el job 6 horas
 * antes en hora local — desastre operacional.
 *
 * Switch global: el job mismo verifica `config('cash.auto_close.enabled')`
 * y aborta limpio si está deshabilitado. Mantenemos la entrada del scheduler
 * activa siempre — desactivar acá requeriría reload del scheduler, mientras
 * que el switch via env es inmediato.
 *
 * Mismas garantías operacionales que los otros jobs:
 *   - withoutOverlapping → si el job aún corre por alguna razón, no se lanza
 *                          una segunda instancia.
 *   - onOneServer        → en futuro multi-server, solo un nodo lo ejecuta.
 */
Schedule::job(new AutoCloseCashSessionsJob)
    ->dailyAt(config('cash.auto_close.hour', '21:00'))
    ->timezone('America/Tegucigalpa')
    ->name('cash-auto-close')
    ->withoutOverlapping()
    ->onOneServer();

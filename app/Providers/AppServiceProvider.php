<?php

namespace App\Providers;

use App\Events\CaiFailoverExecuted;
use App\Events\UserLoggedIn;
use App\Listeners\DispatchUserLoggedIn;
use App\Listeners\LogCaiFailoverActivity;
use App\Listeners\RecordUserLogin;
use App\Services\Alerts\CaiSuccessorResolver;
use App\Services\Alerts\Contracts\ResuelveSucesoresDeCai;
use App\Services\Cai\CaiAvailabilityService;
use App\Services\FiscalPeriods\FiscalPeriodService;
use App\Services\Invoicing\Contracts\ResuelveCorrelativoFactura;
use App\Services\Invoicing\Resolvers\CorrelativoCentralizado;
use App\Services\Invoicing\Resolvers\CorrelativoPorSucursal;
use App\Services\Sales\Tax\SaleTaxCalculator;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ─── Facturación SAR ──────────────────────────────
        // El modo activo se decide en config/invoicing.php (env: INVOICING_MODE).
        // Cambiar de modo es un evento fiscal (requiere actualizar registro SAR).
        $this->app->bind(ResuelveCorrelativoFactura::class, function () {
            return match (config('invoicing.mode')) {
                'por_sucursal' => app(CorrelativoPorSucursal::class),
                default => app(CorrelativoCentralizado::class),
            };
        });

        // ─── Failover de CAI ──────────────────────────────
        // CaiFailoverService depende del contrato, no de la clase concreta.
        // Aplica DIP: el Service (alto nivel) ignora qué implementación resuelve
        // sucesores — los tests pueden proveer un fake que implemente el contrato.
        $this->app->bind(ResuelveSucesoresDeCai::class, CaiSuccessorResolver::class);

        // ─── Disponibilidad de CAI (singleton) ────────────
        // CaiAvailabilityService responde "¿hay CAI emisor para este tipo de
        // documento?" con memo interno por request. Registrado como SINGLETON
        // por la misma razón que FiscalPeriodService: los closures `visible()`
        // de Filament se evalúan por cada fila del listado, y sin memo
        // compartido un render de 50 facturas dispararía 50 queries idénticas
        // solo para decidir si mostrar el botón "Emitir NC".
        //
        // Safe como singleton: el memo es read-through puro, no hay métodos
        // de escritura que compartan estado mutable entre callers.
        $this->app->singleton(CaiAvailabilityService::class);

        // ─── Períodos Fiscales (singleton) ───────────────
        // Registrado como SINGLETON para que sus memos internos
        // (countOverdue, loadFiscalPeriodsMap) amortigüen queries repetidas
        // dentro del mismo HTTP request. Sin esto, cada `app(FiscalPeriodService::class)`
        // resolvería una instancia nueva y el cache sería inútil — cada fila
        // de un listado de facturas volvería a pegarle a la DB para decidir
        // la visibilidad del botón "Anular".
        //
        // Safe: los métodos de escritura (declare, reopen) reciben el período
        // por parámetro y usan lockForUpdate dentro de su propia transacción,
        // así que el singleton no comparte estado mutable entre callers.
        $this->app->singleton(FiscalPeriodService::class);

        // ─── Calculador fiscal de ventas (singleton) ─────
        // SaleTaxCalculator es una clase pura y stateless cuyo único estado es
        // el multiplicador fiscal (1.15 por defecto). Se registra como singleton
        // para:
        //   1. Materializar el multiplier desde config('tax.multiplier') UNA vez
        //      por request — no en cada #[Computed] del POS ni en cada venta.
        //   2. Garantizar que POS y SaleService resuelvan la MISMA instancia y,
        //      por extensión, el mismo multiplier consistente durante todo el
        //      ciclo de vida del request (inmune a cambios en runtime de config).
        //
        // El binding usa closure porque el multiplier se lee de config al momento
        // de resolver — config() no está disponible al registrar el provider.
        $this->app->singleton(
            SaleTaxCalculator::class,
            fn () => new SaleTaxCalculator((float) config('tax.multiplier', 1.15)),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Evento de Laravel -> Nuestro evento de dominio
        Event::listen(Login::class, DispatchUserLoggedIn::class);

        // Nuestro evento de dominio -> Listeners de negocio
        // Para agregar mas comportamiento al login, registrar mas listeners aqui.
        Event::listen(UserLoggedIn::class, RecordUserLogin::class);

        // Failover de CAI -> auditoría en activity_log.
        // LogCaiFailoverActivity implementa ShouldQueue: va por cola para no
        // bloquear el camino crítico del failover ante errores transitorios
        // de la tabla de auditoría.
        Event::listen(CaiFailoverExecuted::class, LogCaiFailoverActivity::class);
    }
}

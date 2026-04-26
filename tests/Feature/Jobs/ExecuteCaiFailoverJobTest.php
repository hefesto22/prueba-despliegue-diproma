<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Authorization\CustomPermission;
use App\Jobs\ExecuteCaiFailoverJob;
use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\User;
use App\Notifications\CaiFailoverFailedNotification;
use App\Services\Alerts\CaiAlertRecipientResolver;
use App\Services\Alerts\CaiFailoverService;
use App\Services\Alerts\Contracts\ResuelveSucesoresDeCai;
use BezhanSalleh\FilamentShield\Support\Utils;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cubre ExecuteCaiFailoverJob:
 *   - Idempotencia por día (Cache::add con key 'cai-failover-executed:YYYY-MM-DD')
 *   - Report vacío → no dispara notificaciones (solo log informativo)
 *   - Bucket skippedNoSuccessor → CaiFailoverFailedNotification a Manage:Cai
 *   - Bucket activated → NO dispara notificación (solo log + activity log)
 *   - Bucket errors → NO dispara notificación (es bug, no caso de negocio)
 *   - Super admin siempre recibe
 *   - Usuarios inactivos no reciben
 *   - Sin destinatarios → no explota, emite warning
 *
 * A diferencia de SendCaiAlertsJobTest (alertas preventivas sobre CAIs sanos),
 * aquí los CAIs están realmente vencidos o agotados al momento del test y el
 * Service ejecuta el failover real. Los escenarios usan CAIs YA críticos (no
 * "por vencer") para disparar el camino que el job debe observar.
 */
class ExecuteCaiFailoverJobTest extends TestCase
{
    use RefreshDatabase;

    private CompanySetting $company;

    private Establishment $matriz;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');
        Cache::forget('cai-failover-executed:'.now()->toDateString());

        $this->company = CompanySetting::factory()->create(['rtn' => '08011999123456']);
        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        // Registrar permiso custom que CustomPermissionsSeeder normalmente crearía.
        Permission::findOrCreate(CustomPermission::ManageCai->value, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        CarbonImmutable::setTestNow('2026-04-18');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function runJob(): void
    {
        (new ExecuteCaiFailoverJob)->handle(
            app(CaiFailoverService::class),
            app(CaiAlertRecipientResolver::class),
        );
    }

    /**
     * CAI activo vencido SIN sucesor pre-registrado → cae en bucket
     * skippedNoSuccessor y debe disparar notificación crítica.
     */
    private function escenarioBloqueoSinSucesor(): CaiRange
    {
        return CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->subDay()->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
            'current_number' => 250,
        ]);
    }

    /**
     * CAI activo vencido + sucesor pre-registrado válido → cae en bucket
     * activated. NO debe disparar notificación.
     */
    private function escenarioFailoverExitoso(): array
    {
        $viejo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->subDay()->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
            'current_number' => 250,
        ]);

        $sucesor = CaiRange::factory()->create([
            'is_active' => false, // pre-registrado, listo para promover
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 501,
            'range_end' => 1000,
            'current_number' => 500,
        ]);

        return ['viejo' => $viejo, 'sucesor' => $sucesor];
    }

    public function test_no_hace_nada_cuando_no_hay_cais_criticos(): void
    {
        Notification::fake();

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $contador = User::factory()->create();
        $contador->assignRole('contador');

        $this->runJob();

        Notification::assertNothingSent();
    }

    public function test_dispara_notificacion_cuando_cai_queda_bloqueado_sin_sucesor(): void
    {
        Notification::fake();

        $this->escenarioBloqueoSinSucesor();

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $contador = User::factory()->create();
        $contador->assignRole('contador');

        $this->runJob();

        Notification::assertSentTo($contador, CaiFailoverFailedNotification::class);
    }

    public function test_super_admin_siempre_recibe_notificacion_de_failover(): void
    {
        Notification::fake();

        $this->escenarioBloqueoSinSucesor();

        $superRole = Role::create(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);

        $admin = User::factory()->create();
        $admin->assignRole($superRole);

        $this->runJob();

        Notification::assertSentTo($admin, CaiFailoverFailedNotification::class);
    }

    public function test_usuarios_inactivos_no_reciben_notificacion(): void
    {
        Notification::fake();

        $this->escenarioBloqueoSinSucesor();

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $activo = User::factory()->create(['is_active' => true]);
        $activo->assignRole('contador');

        $inactivo = User::factory()->create(['is_active' => false]);
        $inactivo->assignRole('contador');

        $this->runJob();

        Notification::assertSentTo($activo, CaiFailoverFailedNotification::class);
        Notification::assertNotSentTo($inactivo, CaiFailoverFailedNotification::class);
    }

    public function test_activacion_exitosa_no_dispara_notificacion(): void
    {
        Notification::fake();

        $this->escenarioFailoverExitoso();

        // Hay un usuario con permiso — si la lógica estuviera mal notificaría
        // sobre activaciones exitosas. El contrato: solo bucket
        // skippedNoSuccessor llega a humanos.
        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $contador = User::factory()->create();
        $contador->assignRole('contador');

        $this->runJob();

        Notification::assertNothingSent();
    }

    public function test_errores_inesperados_no_disparan_notificacion(): void
    {
        Notification::fake();

        // CAI vencido que provocará un error inesperado al intentar buscar sucesor.
        $this->escenarioBloqueoSinSucesor();

        // Resolver fake que lanza excepción genérica (simulando fallo de DB,
        // bug, etc). Cae en bucket `errors`, NO en `skippedNoSuccessor`.
        $this->app->bind(ResuelveSucesoresDeCai::class, fn () => new class implements ResuelveSucesoresDeCai
        {
            public function findSuccessorFor(CaiRange $cai): ?CaiRange
            {
                throw new RuntimeException('Fallo simulado de infraestructura');
            }
        });

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $contador = User::factory()->create();
        $contador->assignRole('contador');

        $this->runJob();

        // Errores inesperados son bugs de oncall, no algo accionable por el
        // contador — verificar que NO se envió notificación.
        Notification::assertNothingSent();
    }

    public function test_idempotente_por_dia(): void
    {
        Notification::fake();

        $this->escenarioBloqueoSinSucesor();

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $contador = User::factory()->create();
        $contador->assignRole('contador');

        // Primera corrida: detecta el CAI bloqueado y notifica.
        $this->runJob();

        // Segunda corrida el mismo día: debe detectarse por Cache::add y salir
        // sin ejecutar el service ni enviar notificaciones nuevas.
        $this->runJob();

        Notification::assertSentToTimes($contador, CaiFailoverFailedNotification::class, 1);
    }

    public function test_sin_destinatarios_no_falla_y_no_envia_nada(): void
    {
        Notification::fake();

        $this->escenarioBloqueoSinSucesor();

        // Ningún usuario con Manage:Cai ni super_admin → el job debe emitir
        // un Log::warning (no verificado acá, lo relevante es que no explote
        // y no envíe nada). La ausencia de destinatarios es un mis-deploy,
        // no un error recuperable vía notificación.

        $this->runJob();

        Notification::assertNothingSent();
    }
}

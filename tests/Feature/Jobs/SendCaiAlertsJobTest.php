<?php

namespace Tests\Feature\Jobs;

use App\Authorization\CustomPermission;
use App\Jobs\SendCaiAlertsJob;
use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\User;
use App\Notifications\CaiExpiringNotification;
use App\Notifications\CaiRangeExhaustingNotification;
use App\Services\Alerts\CaiAlertRecipientResolver;
use App\Services\Alerts\CaiExpirationChecker;
use App\Services\Alerts\CaiRangeExhaustionChecker;
use BezhanSalleh\FilamentShield\Support\Utils;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cubre el job de alerta diaria de CAIs:
 *   - Idempotencia por día (Cache::add)
 *   - Destinatarios correctos (solo activos con Manage:Cai)
 *   - Super admin siempre recibe
 *   - Inactivos no reciben
 *   - Sin alertas → silencio
 *   - Dos notificaciones disparan en paralelo cuando aplica
 *   - No duplica si usuario tiene rol + permiso directo
 */
class SendCaiAlertsJobTest extends TestCase
{
    use RefreshDatabase;

    private CompanySetting $company;

    private Establishment $matriz;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');
        Cache::forget('cai-alerts-sent:' . now()->toDateString());

        $this->company = CompanySetting::factory()->create(['rtn' => '08011999123456']);
        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        // Registrar permiso custom que el seeder normalmente crearía.
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
        (new SendCaiAlertsJob())->handle(
            app(CaiExpirationChecker::class),
            app(CaiRangeExhaustionChecker::class),
            app(CaiAlertRecipientResolver::class),
        );
    }

    /**
     * Crea un escenario con UN CAI activo en alerta de vencimiento (5 días) y
     * sin sucesor → produce alerta Critical.
     */
    private function escenarioExpiration(): void
    {
        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addDays(5)->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);
    }

    /**
     * Crea un escenario con UN CAI activo en alerta de agotamiento sin sucesor.
     * Usa documento '03' (NC) para no chocar con escenarioExpiration en
     * (document_type, establishment_id) UNIQUE.
     */
    private function escenarioExhaustion(): void
    {
        $this->company->update([
            'cai_exhaustion_warning_absolute' => 50,
            'cai_exhaustion_warning_percentage' => 1.0,
        ]);
        // Refrescar cache con el company ya actualizado. NO usar Cache::forget
        // porque CompanySetting::current() usa firstOrCreate(['id' => 1]) y
        // si el factory creó la company con id != 1 se generaría un row fantasma.
        Cache::put('company_settings', $this->company->fresh(), 60 * 60 * 24);

        CaiRange::factory()->active()->create([
            'document_type' => '03',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 1000,
            'current_number' => 960, // remaining 40
            'expiration_date' => now()->addMonths(6)->toDateString(),
        ]);
    }

    public function test_envia_notificacion_expiration_a_usuario_con_permiso(): void
    {
        Notification::fake();

        $this->escenarioExpiration();

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $contador = User::factory()->create();
        $contador->assignRole('contador');

        $sinPermiso = User::factory()->create();

        $this->runJob();

        Notification::assertSentTo($contador, CaiExpiringNotification::class);
        Notification::assertNotSentTo($sinPermiso, CaiExpiringNotification::class);
    }

    public function test_envia_notificacion_exhaustion_a_usuario_con_permiso(): void
    {
        Notification::fake();

        $this->escenarioExhaustion();

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $contador = User::factory()->create();
        $contador->assignRole('contador');

        $this->runJob();

        Notification::assertSentTo($contador, CaiRangeExhaustingNotification::class);
    }

    public function test_envia_ambas_notificaciones_cuando_hay_ambas_alertas(): void
    {
        Notification::fake();

        $this->escenarioExpiration();
        $this->escenarioExhaustion();

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $contador = User::factory()->create();
        $contador->assignRole('contador');

        $this->runJob();

        Notification::assertSentTo($contador, CaiExpiringNotification::class);
        Notification::assertSentTo($contador, CaiRangeExhaustingNotification::class);
    }

    public function test_super_admin_siempre_recibe(): void
    {
        Notification::fake();

        $this->escenarioExpiration();

        $superRole = Role::create(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);

        $admin = User::factory()->create();
        $admin->assignRole($superRole);

        $this->runJob();

        Notification::assertSentTo($admin, CaiExpiringNotification::class);
    }

    public function test_inactivos_no_reciben(): void
    {
        Notification::fake();

        $this->escenarioExpiration();

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $activo = User::factory()->create(['is_active' => true]);
        $activo->assignRole('contador');

        $inactivo = User::factory()->create(['is_active' => false]);
        $inactivo->assignRole('contador');

        $this->runJob();

        Notification::assertSentTo($activo, CaiExpiringNotification::class);
        Notification::assertNotSentTo($inactivo, CaiExpiringNotification::class);
    }

    public function test_no_envia_nada_cuando_no_hay_alertas(): void
    {
        Notification::fake();

        // Sin CAIs creados → checkers retornan vacío.
        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $contador = User::factory()->create();
        $contador->assignRole('contador');

        $this->runJob();

        Notification::assertNothingSent();
    }

    public function test_idempotente_por_dia(): void
    {
        Notification::fake();

        $this->escenarioExpiration();

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $contador = User::factory()->create();
        $contador->assignRole('contador');

        // Primera corrida: envía.
        $this->runJob();

        // Segunda corrida el mismo día: no envía de nuevo.
        $this->runJob();

        Notification::assertSentToTimes($contador, CaiExpiringNotification::class, 1);
    }

    public function test_no_duplica_cuando_usuario_tiene_rol_y_permiso_directo(): void
    {
        Notification::fake();

        $this->escenarioExpiration();

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $user = User::factory()->create();
        $user->assignRole('contador');
        $user->givePermissionTo(CustomPermission::ManageCai->value);

        $this->runJob();

        Notification::assertSentToTimes($user, CaiExpiringNotification::class, 1);
    }
}

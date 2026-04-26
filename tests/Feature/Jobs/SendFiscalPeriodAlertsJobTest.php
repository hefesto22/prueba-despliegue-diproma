<?php

namespace Tests\Feature\Jobs;

use App\Authorization\CustomPermission;
use App\Jobs\SendFiscalPeriodAlertsJob;
use App\Models\CompanySetting;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Notifications\FiscalPeriodsPendingNotification;
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
 * Cubre el job de alerta diaria de períodos fiscales:
 *   - Idempotencia por día (Cache::add)
 *   - Destinatarios correctos (solo usuarios activos con Declare:FiscalPeriod)
 *   - Degradación silenciosa cuando no hay configuración
 *   - Super admin siempre recibe
 */
class SendFiscalPeriodAlertsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');
        Cache::forget('fiscal-period-alerts-sent:' . now()->toDateString());

        CompanySetting::factory()->create([
            'fiscal_period_start' => '2026-01-01',
        ]);

        // Refrescar cache de company_settings porque el factory disparó el observer.
        Cache::put('company_settings', CompanySetting::first(), 60 * 60 * 24);

        // Registrar permiso custom que el seeder normalmente crearía.
        Permission::findOrCreate(CustomPermission::DeclareFiscalPeriod->value, 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Fijar "hoy" para que listOverdue() retorne meses pasados determinísticamente.
        CarbonImmutable::setTestNow('2026-04-17');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_sends_notification_to_users_with_declare_permission(): void
    {
        Notification::fake();

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::DeclareFiscalPeriod->value);

        $contador = User::factory()->create();
        $contador->assignRole('contador');

        $empleadoSinPermiso = User::factory()->create();

        (new SendFiscalPeriodAlertsJob())->handle(app(\App\Services\FiscalPeriods\FiscalPeriodService::class));

        Notification::assertSentTo($contador, FiscalPeriodsPendingNotification::class);
        Notification::assertNotSentTo($empleadoSinPermiso, FiscalPeriodsPendingNotification::class);
    }

    public function test_super_admin_always_receives_even_without_explicit_permission(): void
    {
        Notification::fake();

        $superRole = Role::create(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);

        $admin = User::factory()->create();
        $admin->assignRole($superRole);

        (new SendFiscalPeriodAlertsJob())->handle(app(\App\Services\FiscalPeriods\FiscalPeriodService::class));

        Notification::assertSentTo($admin, FiscalPeriodsPendingNotification::class);
    }

    public function test_inactive_users_do_not_receive(): void
    {
        Notification::fake();

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::DeclareFiscalPeriod->value);

        $activo = User::factory()->create(['is_active' => true]);
        $activo->assignRole('contador');

        $inactivo = User::factory()->create(['is_active' => false]);
        $inactivo->assignRole('contador');

        (new SendFiscalPeriodAlertsJob())->handle(app(\App\Services\FiscalPeriods\FiscalPeriodService::class));

        Notification::assertSentTo($activo, FiscalPeriodsPendingNotification::class);
        Notification::assertNotSentTo($inactivo, FiscalPeriodsPendingNotification::class);
    }

    public function test_is_idempotent_per_day(): void
    {
        Notification::fake();

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::DeclareFiscalPeriod->value);

        $contador = User::factory()->create();
        $contador->assignRole('contador');

        $service = app(\App\Services\FiscalPeriods\FiscalPeriodService::class);

        // Primera corrida: envía.
        (new SendFiscalPeriodAlertsJob())->handle($service);

        // Segunda corrida el mismo día: NO envía de nuevo.
        (new SendFiscalPeriodAlertsJob())->handle($service);

        Notification::assertSentToTimes($contador, FiscalPeriodsPendingNotification::class, 1);
    }

    public function test_skips_silently_when_no_overdue_periods(): void
    {
        Notification::fake();

        // Sin fiscal_period_start → listOverdue() vacío → no envía.
        CompanySetting::first()->update(['fiscal_period_start' => null]);
        Cache::put('company_settings', CompanySetting::first()->fresh(), 60 * 60 * 24);

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::DeclareFiscalPeriod->value);

        $contador = User::factory()->create();
        $contador->assignRole('contador');

        (new SendFiscalPeriodAlertsJob())->handle(app(\App\Services\FiscalPeriods\FiscalPeriodService::class));

        Notification::assertNothingSent();
    }

    public function test_does_not_duplicate_when_user_has_both_role_and_direct_permission(): void
    {
        Notification::fake();

        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::DeclareFiscalPeriod->value);

        $user = User::factory()->create();
        $user->assignRole('contador');
        $user->givePermissionTo(CustomPermission::DeclareFiscalPeriod->value);

        (new SendFiscalPeriodAlertsJob())->handle(app(\App\Services\FiscalPeriods\FiscalPeriodService::class));

        // Debe recibir exactamente 1 notificación aunque matchee por dos rutas.
        Notification::assertSentToTimes($user, FiscalPeriodsPendingNotification::class, 1);
    }
}

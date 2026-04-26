<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\PaymentMethod;
use App\Jobs\AutoCloseCashSessionsJob;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\User;
use App\Notifications\CashSessionAutoClosedNotification;
use App\Services\Cash\CashSessionService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\SystemUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cubre AutoCloseCashSessionsJob:
 *
 *   - Switch global desactivado → no cierra ni notifica.
 *   - Sin sesiones abiertas → no-op silencioso.
 *   - Cierra todas las sesiones abiertas con expected calculado.
 *   - Notifica al cajero (openedBy) + admins activos.
 *   - Excluye admins inactivos.
 *   - No notifica dos veces al mismo user (cuando el cajero también es admin).
 *   - Si una sesión ya está cerrada (race con cierre manual), el job sigue
 *     con las demás sin abortar (carrera benigna).
 *   - El cierre se atribuye al system user en el CashMovement.
 */
class AutoCloseCashSessionsJobTest extends TestCase
{
    use RefreshDatabase;

    private CompanySetting $company;

    private Establishment $matriz;

    private CashSessionService $cashSessions;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');
        // Cache estática de User::system() sobrevive entre tests aunque la BD
        // se trunque con RefreshDatabase — invalidamos antes del seed para que
        // el siguiente User::system() lea el id correcto del seeder.
        User::clearSystemUserCache();

        $this->company = CompanySetting::factory()->create([
            'rtn' => '08011999123456',
            'cash_discrepancy_tolerance' => 50.00,
        ]);
        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        $this->seed(SystemUserSeeder::class);

        // El job depende de los roles spatie; los creamos para que activeAdmins() funcione.
        Role::findOrCreate(Utils::getSuperAdminName(), 'web');
        Role::findOrCreate('admin', 'web');

        $this->cashSessions = app(CashSessionService::class);

        // Default: switch encendido (que no se filtre estado de un test al otro).
        config(['cash.auto_close.enabled' => true]);
    }

    private function runJob(): void
    {
        (new AutoCloseCashSessionsJob())->handle($this->cashSessions);
    }

    private function makeAdmin(bool $active = true): User
    {
        $admin = User::factory()->create(['is_active' => $active]);
        $admin->assignRole('admin');
        return $admin;
    }

    // ─── Switch global ────────────────────────────────────────

    public function test_switch_global_desactivado_no_cierra_ni_notifica(): void
    {
        config(['cash.auto_close.enabled' => false]);
        Notification::fake();

        $cajero = User::factory()->create();
        $session = $this->cashSessions->open($this->matriz->id, $cajero, 1000.00);
        $admin = $this->makeAdmin();

        $this->runJob();

        // La sesión sigue abierta — el job ni la tocó.
        $this->assertTrue($session->fresh()->isOpen());
        Notification::assertNothingSent();
    }

    // ─── No-op cuando no hay sesiones abiertas ────────────────

    public function test_sin_sesiones_abiertas_es_no_op_silencioso(): void
    {
        Notification::fake();
        $this->makeAdmin();

        $this->runJob();

        Notification::assertNothingSent();
        $this->assertSame(0, CashSession::query()->count());
    }

    // ─── Cierra sesiones abiertas con expected calculado ──────

    public function test_cierra_sesion_abierta_calculando_expected_y_marcando_pendiente(): void
    {
        Notification::fake();

        $cajero = User::factory()->create();
        $session = $this->cashSessions->open($this->matriz->id, $cajero, 1000.00);
        CashMovement::factory()->forSession($session)->saleIncome(500.00, PaymentMethod::Efectivo)->create();
        CashMovement::factory()->forSession($session)->expense(100.00)->create();

        $this->runJob();

        $closed = $session->fresh();
        $this->assertTrue($closed->isClosed());
        $this->assertTrue($closed->wasClosedBySystem());
        $this->assertTrue($closed->isPendingReconciliation());
        $this->assertSame('1400.00', $closed->expected_closing_amount);
        $this->assertNull($closed->actual_closing_amount);
    }

    // ─── Notifica al cajero + admins activos ──────────────────

    public function test_notifica_al_cajero_y_a_admins_activos(): void
    {
        Notification::fake();

        $cajero = User::factory()->create();
        $this->cashSessions->open($this->matriz->id, $cajero, 1000.00);

        $admin1 = $this->makeAdmin();
        $admin2 = $this->makeAdmin();

        // SuperAdmin activo: también debe recibir.
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Utils::getSuperAdminName());

        $this->runJob();

        Notification::assertSentTo($cajero,     CashSessionAutoClosedNotification::class);
        Notification::assertSentTo($admin1,     CashSessionAutoClosedNotification::class);
        Notification::assertSentTo($admin2,     CashSessionAutoClosedNotification::class);
        Notification::assertSentTo($superAdmin, CashSessionAutoClosedNotification::class);
    }

    // ─── Excluye admins inactivos ─────────────────────────────

    public function test_no_notifica_a_admins_inactivos(): void
    {
        Notification::fake();

        $cajero = User::factory()->create();
        $this->cashSessions->open($this->matriz->id, $cajero, 1000.00);

        $adminActivo   = $this->makeAdmin(active: true);
        $adminInactivo = $this->makeAdmin(active: false);

        $this->runJob();

        Notification::assertSentTo($adminActivo, CashSessionAutoClosedNotification::class);
        Notification::assertNotSentTo($adminInactivo, CashSessionAutoClosedNotification::class);
    }

    // ─── No duplica notificación si cajero es admin ───────────

    public function test_si_el_cajero_tambien_es_admin_solo_recibe_una_notificacion(): void
    {
        Notification::fake();

        // El cajero tiene rol admin además de operar caja (caso real: dueño/admin
        // que abre la caja él mismo). El reject del job lo excluye de la rama
        // de admins para evitar enviar dos notificaciones idénticas.
        $cajero = User::factory()->create();
        $cajero->assignRole('admin');
        $this->cashSessions->open($this->matriz->id, $cajero, 1000.00);

        $this->runJob();

        Notification::assertSentToTimes($cajero, CashSessionAutoClosedNotification::class, 1);
    }

    // ─── Resiliencia: convivencia con cierres manuales previos ─

    public function test_no_toca_sesiones_que_ya_fueron_cerradas_manualmente(): void
    {
        Notification::fake();

        $cajero = User::factory()->create();
        $admin = $this->makeAdmin();

        // Sesión A en matriz: cerrada manualmente por el cajero antes del job.
        $sessionA = $this->cashSessions->open($this->matriz->id, $cajero, 1000.00);
        $this->cashSessions->close($sessionA->fresh(), $cajero, 1000.00);

        // Sucursal B con su propia caja todavía abierta — esta sí debe auto-cerrarse.
        $sucursalB = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create(['is_main' => false]);
        $sessionB = $this->cashSessions->open($sucursalB->id, $cajero, 500.00);

        $this->runJob();

        // B sí fue auto-cerrada por el job.
        $this->assertTrue($sessionB->fresh()->isPendingReconciliation());

        // A se mantuvo cerrada por humano (no auto-cerrada).
        $this->assertFalse($sessionA->fresh()->isPendingReconciliation());
        $this->assertNull($sessionA->fresh()->closed_by_system_at);

        // Solo una notificación por destinatario (la de la sesión B).
        Notification::assertSentToTimes($cajero, CashSessionAutoClosedNotification::class, 1);
        Notification::assertSentToTimes($admin, CashSessionAutoClosedNotification::class, 1);
    }

    // ─── Atribución del CashMovement de cierre ────────────────

    public function test_el_movimiento_de_cierre_se_atribuye_al_system_user(): void
    {
        Notification::fake();

        $cajero = User::factory()->create();
        $session = $this->cashSessions->open($this->matriz->id, $cajero, 1000.00);

        $this->runJob();

        $closingMovement = $session->fresh()->movements()
            ->where('description', 'Cierre automático del sistema')
            ->first();

        $this->assertNotNull($closingMovement);

        // El user_id apunta al system user (no al cajero) — auditoría limpia
        // del actor que hizo el cierre.
        $systemUser = User::system();
        $this->assertSame($systemUser->id, $closingMovement->user_id);
        $this->assertNotSame($cajero->id, $closingMovement->user_id);
    }
}

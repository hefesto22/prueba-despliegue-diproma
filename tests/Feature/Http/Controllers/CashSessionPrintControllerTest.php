<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Feature test del CashSessionPrintController.
 *
 * Cubre el contrato HTTP del endpoint /cash-sessions/{cashSession}:
 *   - Auth obligatoria (middleware 'auth')
 *   - Autorización via CashSessionPolicy@view
 *   - Render con datos esperados en la respuesta
 *   - 404 para records inexistentes (route model binding)
 *
 * No re-testea el shape del payload — eso lo cubre CashSessionPrintServiceTest.
 * Estos tests validan la integración HTTP + auth + view rendering.
 */
class CashSessionPrintControllerTest extends TestCase
{
    use RefreshDatabase, CreatesMatriz;

    protected function setUp(): void
    {
        parent::setUp();

        // Bypass Gate para super_admin — mismo patrón que CashSessionResourceTest.
        Role::firstOrCreate([
            'name' => Utils::getSuperAdminName(),
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Gate::before(function ($user) {
            if ($user instanceof User && $user->hasRole(Utils::getSuperAdminName())) {
                return true;
            }

            return null;
        });

        Cache::put('company_settings', $this->matrizCompany->fresh(), 60 * 60 * 24);
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'default_establishment_id' => $this->matriz->id,
        ]);
        $user->assignRole(Utils::getSuperAdminName());

        return $user;
    }

    private function makeSession(): CashSession
    {
        return CashSession::factory()
            ->forEstablishment($this->matriz)
            ->openedBy($this->makeAdminUser())
            ->openingAmount(1000.00)
            ->create([
                'closed_at' => now(),
                'expected_closing_amount' => 1000.00,
                'actual_closing_amount' => 1000.00,
                'discrepancy' => 0.00,
            ]);
    }

    // ─── Auth ────────────────────────────────────────────────

    public function test_invitado_es_redirigido_al_login(): void
    {
        $session = $this->makeSession();

        $response = $this->get(route('cash-sessions.print', $session));

        $response->assertRedirect();
        // Filament puede tener su propio login; lo importante es que NO
        // devuelve 200 con el contenido al invitado.
        $this->assertNotSame(200, $response->status());
    }

    // ─── Autorización ────────────────────────────────────────

    public function test_user_sin_permiso_view_recibe_403(): void
    {
        $session = $this->makeSession();

        // User sin role super_admin y sin permiso View:CashSession.
        $userSinPermisos = User::factory()->create(['is_active' => true]);

        $this->actingAs($userSinPermisos)
            ->get(route('cash-sessions.print', $session))
            ->assertForbidden();
    }

    public function test_user_con_super_admin_recibe_200(): void
    {
        $session = $this->makeSession();
        $admin = $this->makeAdminUser();

        $this->actingAs($admin)
            ->get(route('cash-sessions.print', $session))
            ->assertOk();
    }

    // ─── Render ──────────────────────────────────────────────

    public function test_view_incluye_datos_clave_de_la_sesion(): void
    {
        $session = $this->makeSession();

        // Algunos movimientos para que el kardex y los agregados tengan contenido.
        CashMovement::factory()->forSession($session)->saleIncome(500.00, PaymentMethod::Efectivo)->create();
        CashMovement::factory()->forSession($session)->expense(75.00, ExpenseCategory::Combustible)->create();

        $admin = $this->makeAdminUser();

        $response = $this->actingAs($admin)
            ->get(route('cash-sessions.print', $session));

        $response->assertOk();
        $response->assertViewIs('cash-sessions.print');
        $response->assertSee('CIERRE DE CAJA', false);
        $response->assertSee('Sesión #' . $session->id, false);
        $response->assertSee($this->matriz->name);
        $response->assertSee('Combustible'); // categoría aparece en el desglose
        $response->assertSee('1,000.00');    // monto apertura formateado
    }

    public function test_view_para_sesion_abierta_muestra_banner_corte_parcial(): void
    {
        $admin = $this->makeAdminUser();

        $sessionAbierta = CashSession::factory()
            ->forEstablishment($this->matriz)
            ->openedBy($admin)
            ->openingAmount(500.00)
            ->create(); // closed_at = null

        $response = $this->actingAs($admin)
            ->get(route('cash-sessions.print', $sessionAbierta));

        $response->assertOk();
        $response->assertSee('CORTE PARCIAL', false);
        $response->assertSee('SESIÓN ABIERTA', false);
    }

    // ─── 404 ─────────────────────────────────────────────────

    public function test_devuelve_404_para_sesion_inexistente(): void
    {
        $admin = $this->makeAdminUser();

        $this->actingAs($admin)
            ->get('/cash-sessions/9999999')
            ->assertNotFound();
    }
}

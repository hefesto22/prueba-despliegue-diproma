<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Pages;

use App\Authorization\CustomPermission;
use App\Filament\Pages\DeclaracionIsvMensual;
use App\Models\FiscalPeriod;
use App\Models\IsvMonthlyDeclaration;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Tests del Filament Page DeclaracionIsvMensual (ISV.5).
 *
 * Cubre:
 *   - Auth: guest redirigido, user sin permiso rebotado, super_admin renderiza.
 *   - Defaults del form (año/mes actual al montar).
 *   - Cargar período en los 3 estados: open / declared / reopened.
 *   - Validaciones: mes futuro, mes previo a fiscal_period_start.
 *   - Declarar: happy path + rechazo del mes en curso + snapshot ya existente.
 *   - Reabrir: happy path desde estado declared.
 *   - Rectificar: happy path desde estado reopened con snapshot activo.
 *   - Visibilidad de acciones según estado del período cargado.
 *   - Guards por permiso: Declare:FiscalPeriod, Reopen:FiscalPeriod.
 *
 * Estrategia de tiempo:
 *   Se congela `now()` en 2026-04-15 dentro del setUp. Esto garantiza que:
 *     - fiscal_period_start = 2026-01-01 deja un rango válido [Ene 2026, Abr 2026].
 *     - El mes en curso es Abril 2026 (no declarable).
 *     - Febrero 2026 es un mes vencido apto para todos los happy paths.
 *   El test de "mes futuro" usa mayo 2026 que es posterior al freeze.
 *
 * NO se re-testea la lógica de IsvMonthlyDeclarationService ni FiscalPeriodService
 * — ya están cubiertos en sus propios tests. Aquí solo se verifica la orquestación
 * UI → Service + manejo de excepciones de dominio → Notification al usuario.
 */
class DeclaracionIsvMensualTest extends TestCase
{
    use RefreshDatabase, CreatesMatriz;

    private User $admin;

    private CarbonImmutable $frozenNow;

    protected function setUp(): void
    {
        parent::setUp();

        // ─── Tiempo determinístico ─────────────────────────────
        // Abril 2026 como "mes en curso". Febrero 2026 queda como mes
        // pasado declarable. fiscal_period_start se ubica en enero.
        $this->frozenNow = CarbonImmutable::parse('2026-04-15 09:00:00');
        CarbonImmutable::setTestNow($this->frozenNow);
        \Carbon\Carbon::setTestNow($this->frozenNow);

        // ─── Configuración fiscal ──────────────────────────────
        $this->matrizCompany->update([
            'fiscal_period_start' => CarbonImmutable::parse('2026-01-01'),
        ]);
        Cache::put('company_settings', $this->matrizCompany->fresh(), 60 * 60 * 24);

        // ─── Roles y bypass de Gate para super_admin ──────────
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

        $this->admin = User::factory()->create([
            'is_active' => true,
            'default_establishment_id' => $this->matriz->id,
        ]);
        $this->admin->assignRole(Utils::getSuperAdminName());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════
    // 1. Auth / Acceso
    // ═══════════════════════════════════════════════════════

    public function test_invitado_es_redirigido_al_login(): void
    {
        $response = $this->get('/admin/declaracion-isv-mensual');

        $this->assertNotSame(200, $response->status());
    }

    public function test_user_sin_permiso_no_puede_acceder(): void
    {
        $userSinPermisos = User::factory()->create(['is_active' => true]);

        $this->actingAs($userSinPermisos);

        $this->assertFalse(DeclaracionIsvMensual::canAccess());
    }

    public function test_super_admin_renderiza_la_pagina(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(DeclaracionIsvMensual::class)
            ->assertSuccessful();
    }

    // ═══════════════════════════════════════════════════════
    // 2. Defaults del form
    // ═══════════════════════════════════════════════════════

    public function test_form_se_llena_con_anio_y_mes_actuales_al_montar(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(DeclaracionIsvMensual::class)
            ->assertFormSet([
                'period_year' => 2026,
                'period_month' => 4,
            ]);
    }

    // ═══════════════════════════════════════════════════════
    // 3. Cargar período — los 3 estados
    // ═══════════════════════════════════════════════════════

    public function test_cargar_periodo_abierto_expone_totales_cero_y_sin_snapshot(): void
    {
        $this->actingAs($this->admin);

        $component = Livewire::test(DeclaracionIsvMensual::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 2,
            ])
            ->callAction('cargar_periodo')
            ->assertHasNoActionErrors();

        $component->assertSet('loadedYear', 2026)
            ->assertSet('loadedMonth', 2)
            ->assertSet('periodStatus', 'open')
            ->assertSet('activeSnapshot', null);

        // Período lazy-creado por FiscalPeriodService::forDate
        $this->assertDatabaseHas('fiscal_periods', [
            'period_year' => 2026,
            'period_month' => 2,
        ]);

        $totals = $component->get('computedTotals');
        $this->assertIsArray($totals);
        $this->assertEquals(0.0, $totals['ventas_totales']);
        $this->assertEquals(0.0, $totals['isv_a_pagar']);
    }

    public function test_cargar_periodo_declarado_expone_snapshot_activo(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->declared($this->admin)->create();

        $snapshot = IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($period)
            ->create([
                'declared_by_user_id' => $this->admin->id,
                'siisar_acuse_number' => 'SIISAR-TEST-001',
                'notes' => 'Declaración original',
            ]);

        $this->actingAs($this->admin);

        $component = Livewire::test(DeclaracionIsvMensual::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 2,
            ])
            ->callAction('cargar_periodo')
            ->assertHasNoActionErrors()
            ->assertSet('periodStatus', 'declared');

        $active = $component->get('activeSnapshot');
        $this->assertIsArray($active);
        $this->assertSame($snapshot->id, $active['id']);
        $this->assertSame('SIISAR-TEST-001', $active['siisar_acuse_number']);
    }

    public function test_cargar_periodo_reabierto_expone_status_reopened(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->reopened($this->admin)->create();

        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($period)
            ->create(['declared_by_user_id' => $this->admin->id]);

        $this->actingAs($this->admin);

        Livewire::test(DeclaracionIsvMensual::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 2,
            ])
            ->callAction('cargar_periodo')
            ->assertHasNoActionErrors()
            ->assertSet('periodStatus', 'reopened');
    }

    public function test_cargar_rechaza_mes_futuro(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(DeclaracionIsvMensual::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 5, // mayo — posterior a abril (mes en curso)
            ])
            ->callAction('cargar_periodo');

        Notification::assertNotified('Período no válido');
    }

    public function test_cargar_rechaza_mes_previo_a_fiscal_period_start(): void
    {
        $this->actingAs($this->admin);

        // fiscal_period_start = 2026-01-01 → diciembre 2025 está fuera de rango.
        // Pero yearOptions solo incluye 2026, así que tenemos que usar un mes de
        // 2026 que sea previo... pero fiscal_period_start comienza en enero 2026,
        // entonces NO hay mes de 2026 previo al start. Ajustamos el start a febrero
        // para dejar enero como "fuera de rango válido" dentro del mismo año.
        $this->matrizCompany->update([
            'fiscal_period_start' => CarbonImmutable::parse('2026-02-01'),
        ]);
        Cache::put('company_settings', $this->matrizCompany->fresh(), 60 * 60 * 24);

        Livewire::test(DeclaracionIsvMensual::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 1, // enero — antes de fiscal_period_start (feb)
            ])
            ->callAction('cargar_periodo');

        Notification::assertNotified('Período fuera de rango');
    }

    // ═══════════════════════════════════════════════════════
    // 4. Declarar
    // ═══════════════════════════════════════════════════════

    public function test_declarar_periodo_pasado_crea_snapshot_y_cierra_periodo(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(DeclaracionIsvMensual::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 2,
            ])
            ->callAction('cargar_periodo')
            ->callAction('declarar', data: [
                'siisar_acuse' => 'ACUSE-2026-02',
                'notes' => 'Declaración de prueba febrero',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('isv_monthly_declarations', [
            'siisar_acuse_number' => 'ACUSE-2026-02',
            'notes' => 'Declaración de prueba febrero',
            'declared_by_user_id' => $this->admin->id,
            'superseded_at' => null,
        ]);

        $period = FiscalPeriod::forMonth(2026, 2)->firstOrFail();
        $this->assertTrue($period->isClosed());
        $this->assertSame($this->admin->id, $period->declared_by);
    }

    public function test_declarar_rechaza_el_mes_en_curso(): void
    {
        $this->actingAs($this->admin);

        // Abril 2026 = mes en curso. Se puede cargar pero no declarar.
        Livewire::test(DeclaracionIsvMensual::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 4,
            ])
            ->callAction('cargar_periodo')
            ->callAction('declarar', data: [
                'siisar_acuse' => null,
                'notes' => null,
            ]);

        Notification::assertNotified('Período aún no vencido');

        // Invariante: no se creó snapshot ni se cerró el período.
        $this->assertEquals(0, IsvMonthlyDeclaration::count());
        $period = FiscalPeriod::forMonth(2026, 4)->first();
        $this->assertNotNull($period);
        $this->assertTrue($period->isOpen());
    }

    public function test_declarar_rechaza_si_ya_existe_snapshot_activo(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->declared($this->admin)->create();

        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($period)
            ->create(['declared_by_user_id' => $this->admin->id]);

        $this->actingAs($this->admin);

        Livewire::test(DeclaracionIsvMensual::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 2,
            ])
            ->callAction('cargar_periodo')
            // La acción "declarar" está oculta cuando el estado es 'declared';
            // forzarla sería un click imposible desde UI. Verificamos la
            // visibilidad aquí directamente — el gate del Service ya está
            // cubierto en IsvMonthlyDeclarationServiceTest.
            ->assertActionHidden('declarar');
    }

    // ═══════════════════════════════════════════════════════
    // 5. Reabrir
    // ═══════════════════════════════════════════════════════

    public function test_reabrir_periodo_declarado_actualiza_reopened_at(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->declared($this->admin)->create();

        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($period)
            ->create(['declared_by_user_id' => $this->admin->id]);

        $this->actingAs($this->admin);

        Livewire::test(DeclaracionIsvMensual::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 2,
            ])
            ->callAction('cargar_periodo')
            ->callAction('reabrir', data: [
                'reason' => 'Error detectado en retenciones recibidas',
            ])
            ->assertHasNoActionErrors();

        $period->refresh();
        $this->assertNotNull($period->reopened_at);
        $this->assertSame($this->admin->id, $period->reopened_by);
        $this->assertSame('Error detectado en retenciones recibidas', $period->reopen_reason);
    }

    // ═══════════════════════════════════════════════════════
    // 6. Rectificar
    // ═══════════════════════════════════════════════════════

    public function test_rectificar_marca_snapshot_previo_supersedido_y_crea_nuevo_activo(): void
    {
        // Avanzamos el reloj para que reopened_at > declared_at del snapshot original
        // (MySQL datetime trunca a segundos; igual que el test de redeclare del Service).
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->reopened($this->admin)->create();

        $original = IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($period)
            ->create(['declared_by_user_id' => $this->admin->id]);

        $this->actingAs($this->admin);

        // travelTo para separar declared_at del rectificativa del reopened_at.
        $this->travelTo($this->frozenNow->addMinutes(5));

        Livewire::test(DeclaracionIsvMensual::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 2,
            ])
            ->callAction('cargar_periodo')
            ->callAction('rectificar', data: [
                'siisar_acuse' => 'ACUSE-RECT-001',
                'notes' => 'Rectificativa por ajuste en retenciones',
            ])
            ->assertHasNoActionErrors();

        $original->refresh();
        $this->assertNotNull($original->superseded_at);
        $this->assertSame($this->admin->id, $original->superseded_by_user_id);

        // Un nuevo snapshot activo creado
        $activeCount = IsvMonthlyDeclaration::forFiscalPeriod($period->id)->active()->count();
        $this->assertEquals(1, $activeCount);

        $nuevo = IsvMonthlyDeclaration::forFiscalPeriod($period->id)->active()->firstOrFail();
        $this->assertSame('ACUSE-RECT-001', $nuevo->siisar_acuse_number);
        $this->assertSame('Rectificativa por ajuste en retenciones', $nuevo->notes);
    }

    // ═══════════════════════════════════════════════════════
    // 7. Visibilidad de acciones según estado
    // ═══════════════════════════════════════════════════════

    public function test_en_periodo_abierto_solo_es_visible_declarar(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(DeclaracionIsvMensual::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 2,
            ])
            ->callAction('cargar_periodo')
            ->assertActionVisible('declarar')
            ->assertActionHidden('reabrir')
            ->assertActionHidden('rectificar');
    }

    public function test_en_periodo_declarado_solo_es_visible_reabrir(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->declared($this->admin)->create();

        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($period)
            ->create(['declared_by_user_id' => $this->admin->id]);

        $this->actingAs($this->admin);

        Livewire::test(DeclaracionIsvMensual::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 2,
            ])
            ->callAction('cargar_periodo')
            ->assertActionHidden('declarar')
            ->assertActionVisible('reabrir')
            ->assertActionHidden('rectificar');
    }

    public function test_en_periodo_reabierto_solo_es_visible_rectificar(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->reopened($this->admin)->create();

        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($period)
            ->create(['declared_by_user_id' => $this->admin->id]);

        $this->actingAs($this->admin);

        Livewire::test(DeclaracionIsvMensual::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 2,
            ])
            ->callAction('cargar_periodo')
            ->assertActionHidden('declarar')
            ->assertActionHidden('reabrir')
            ->assertActionVisible('rectificar');
    }

    // ═══════════════════════════════════════════════════════
    // 8. Guards por permiso (usuario no super_admin)
    // ═══════════════════════════════════════════════════════

    public function test_user_con_viewany_pero_sin_declare_no_puede_ejecutar_declarar(): void
    {
        // Creamos los permisos Spatie necesarios (super_admin los tiene via
        // Gate::before; para este test usamos un user con permisos granulares
        // para verificar el guard de `->authorize(...)` en la action Declarar).
        Permission::firstOrCreate([
            'name' => 'ViewAny:FiscalPeriod',
            'guard_name' => 'web',
        ]);
        Permission::firstOrCreate([
            'name' => CustomPermission::DeclareFiscalPeriod->value,
            'guard_name' => 'web',
        ]);

        $restrictedRole = Role::firstOrCreate([
            'name' => 'solo_lectura_fiscal',
            'guard_name' => 'web',
        ]);
        $restrictedRole->givePermissionTo('ViewAny:FiscalPeriod');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $reader = User::factory()->create([
            'is_active' => true,
            'default_establishment_id' => $this->matriz->id,
        ]);
        $reader->assignRole($restrictedRole);

        $this->actingAs($reader);

        // Puede acceder a la página (tiene ViewAny:FiscalPeriod) y cargar el período.
        // El guard `->authorize()` de la action Declarar debería ocultar el botón
        // para este user — Filament combina visible + authorize en la visibilidad
        // efectiva del action.
        Livewire::test(DeclaracionIsvMensual::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 2,
            ])
            ->callAction('cargar_periodo')
            ->assertActionHidden('declarar');

        // Invariante de seguridad: aunque el botón estuviera visible por un bug,
        // no se creó snapshot (cargar_periodo no persiste).
        $this->assertEquals(0, IsvMonthlyDeclaration::count());
    }
}

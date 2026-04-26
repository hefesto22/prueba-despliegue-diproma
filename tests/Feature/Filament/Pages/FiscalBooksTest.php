<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Pages;

use App\Exports\PurchaseBook\PurchaseBookExport;
use App\Exports\SalesBook\SalesBookExport;
use App\Filament\Pages\FiscalBooks;
use App\Models\Invoice;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Tests del Filament Page FiscalBooks (DT2).
 *
 * Cubre:
 *   - Acceso autorizado (super_admin renderiza).
 *   - Acceso denegado para users sin permiso ViewAny:FiscalPeriod.
 *   - Defaults del form (año/mes actual al montar).
 *   - Validación de rango: rechaza mes futuro y mes anterior a fiscal_period_start.
 *   - Validación de configuración: rechaza si fiscal_period_start es null.
 *   - Generación exitosa del Libro de Ventas y Libro de Compras (Excel::fake).
 *
 * NO re-testea el contenido de los Excel ni la lógica de SalesBookService /
 * PurchaseBookService — eso vive en SalesBookServiceTest, PurchaseBookServiceTest,
 * SalesBookExportTest y PurchaseBookExportTest. Acá solo verifico la orquestación
 * UI → Service → Export.
 */
class FiscalBooksTest extends TestCase
{
    use RefreshDatabase, CreatesMatriz;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // fiscal_period_start: 1 mes hacia atrás del actual para tener un rango válido
        // (mes pasado + mes actual). Determinístico independiente de cuándo se corra.
        $start = CarbonImmutable::now()->startOfMonth()->subMonth();
        $this->matrizCompany->update(['fiscal_period_start' => $start]);
        Cache::put('company_settings', $this->matrizCompany->fresh(), 60 * 60 * 24);

        // Bypass Gate para super_admin — mismo patrón que CashSessionResourceTest
        // (Shield no siempre registra el hook a tiempo en contexto Livewire).
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

    // ─── Auth / Acceso ───────────────────────────────────────

    public function test_invitado_es_redirigido_al_login(): void
    {
        $response = $this->get('/admin/fiscal-books');

        // Filament redirige al login para guests. Lo importante es que no devuelve 200.
        $this->assertNotSame(200, $response->status());
    }

    public function test_user_sin_permiso_no_puede_acceder(): void
    {
        $userSinPermisos = User::factory()->create(['is_active' => true]);

        $this->actingAs($userSinPermisos);

        // canAccess() de Filament Page dispara 403 cuando retorna false.
        $this->assertFalse(FiscalBooks::canAccess());
    }

    public function test_super_admin_renderiza_la_pagina(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(FiscalBooks::class)
            ->assertSuccessful();
    }

    // ─── Defaults del form ────────────────────────────────────

    public function test_form_se_llena_con_anio_y_mes_actuales_al_montar(): void
    {
        $this->actingAs($this->admin);

        $now = CarbonImmutable::now();

        Livewire::test(FiscalBooks::class)
            ->assertFormSet([
                'period_year' => $now->year,
                'period_month' => $now->month,
                'establishment_id' => null,
            ]);
    }

    // ─── Generación exitosa ───────────────────────────────────

    public function test_descarga_libro_de_ventas_del_mes_actual(): void
    {
        Excel::fake();
        $this->actingAs($this->admin);

        $now = CarbonImmutable::now();

        Livewire::test(FiscalBooks::class)
            ->fillForm([
                'period_year' => $now->year,
                'period_month' => $now->month,
                'establishment_id' => null,
            ])
            ->callAction('libro_ventas')
            ->assertHasNoActionErrors();

        Excel::assertDownloaded(
            (new SalesBookExport(
                app(\App\Services\FiscalBooks\SalesBookService::class)->build($now->year, $now->month)
            ))->fileName()
        );
    }

    public function test_descarga_libro_de_compras_del_mes_actual(): void
    {
        Excel::fake();
        $this->actingAs($this->admin);

        $now = CarbonImmutable::now();

        Livewire::test(FiscalBooks::class)
            ->fillForm([
                'period_year' => $now->year,
                'period_month' => $now->month,
                'establishment_id' => null,
            ])
            ->callAction('libro_compras')
            ->assertHasNoActionErrors();

        Excel::assertDownloaded(
            (new PurchaseBookExport(
                app(\App\Services\FiscalBooks\PurchaseBookService::class)->build($now->year, $now->month)
            ))->fileName()
        );
    }

    public function test_descarga_libro_de_ventas_filtrado_por_sucursal(): void
    {
        Excel::fake();
        $this->actingAs($this->admin);

        $now = CarbonImmutable::now();

        Livewire::test(FiscalBooks::class)
            ->fillForm([
                'period_year' => $now->year,
                'period_month' => $now->month,
                'establishment_id' => $this->matriz->id,
            ])
            ->callAction('libro_ventas')
            ->assertHasNoActionErrors();

        Excel::assertDownloaded(
            (new SalesBookExport(
                app(\App\Services\FiscalBooks\SalesBookService::class)
                    ->build($now->year, $now->month, $this->matriz->id)
            ))->fileName()
        );
    }

    // ─── Validaciones de rango ────────────────────────────────

    public function test_rechaza_mes_futuro(): void
    {
        Excel::fake();
        $this->actingAs($this->admin);

        $future = CarbonImmutable::now()->addMonths(2);

        Livewire::test(FiscalBooks::class)
            ->fillForm([
                'period_year' => $future->year,
                'period_month' => $future->month,
                'establishment_id' => null,
            ])
            ->callAction('libro_ventas');

        // La validación interceptó: debe haber enviado la notificación de rechazo.
        // (Maatwebsite Excel fake no expone assertNothingExported; verificamos el
        // comportamiento observable correcto — el mensaje al usuario.)
        Notification::assertNotified('Período no válido');
    }

    public function test_rechaza_mes_anterior_a_fiscal_period_start(): void
    {
        // Freeze time en abril 2026 para determinismo: yearOptions contiene
        // solo [2026] cuando fiscal_period_start está en el mismo año, así que
        // el mes "fuera de rango" debe estar en ese mismo año (Enero 2026 vs
        // fiscal_period_start = Marzo 2026). Si eligiéramos un año distinto,
        // el Select de Filament descarta el value al no estar en options y la
        // validación nunca se ejecuta — la notificación no se enviaría.
        $fakeNow = CarbonImmutable::parse('2026-04-15');
        CarbonImmutable::setTestNow($fakeNow);
        \Carbon\Carbon::setTestNow($fakeNow);

        Excel::fake();
        $this->actingAs($this->admin);

        // fiscal_period_start = Marzo 2026 → rango válido = [Marzo 2026, Abril 2026].
        $this->matrizCompany->update([
            'fiscal_period_start' => CarbonImmutable::parse('2026-03-01'),
        ]);
        Cache::put('company_settings', $this->matrizCompany->fresh(), 60 * 60 * 24);

        Livewire::test(FiscalBooks::class)
            ->fillForm([
                'period_year' => 2026,
                'period_month' => 1, // Enero 2026 — antes de fiscal_period_start, pero año sí está en options.
                'establishment_id' => null,
            ])
            ->callAction('libro_ventas');

        Notification::assertNotified('Período fuera de rango');

        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
    }

    public function test_rechaza_descarga_sin_fiscal_period_start_configurado(): void
    {
        Excel::fake();
        $this->actingAs($this->admin);

        // Limpiamos la configuración para simular un setup incompleto.
        $this->matrizCompany->update(['fiscal_period_start' => null]);
        Cache::put('company_settings', $this->matrizCompany->fresh(), 60 * 60 * 24);

        $now = CarbonImmutable::now();

        Livewire::test(FiscalBooks::class)
            ->fillForm([
                'period_year' => $now->year,
                'period_month' => $now->month,
                'establishment_id' => null,
            ])
            ->callAction('libro_ventas');

        Notification::assertNotified('Configuración incompleta');
    }
}

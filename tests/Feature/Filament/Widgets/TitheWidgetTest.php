<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Widgets;

use App\Authorization\CustomPermission;
use App\Enums\SaleStatus;
use App\Filament\Widgets\TitheWidget;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tithe;
use App\Models\TitheDeduction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Tarjeta "Diezmo" del Escritorio: visible solo con Calculate:Tithe; el modal
 * guarda el diezmo del mes elegido y carga los pagos ya guardados.
 */
class TitheWidgetTest extends TestCase
{
    use CreatesMatriz, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-30 10:00:00');
        \Carbon\Carbon::setTestNow('2026-09-30 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    private function userWithTithePermission(bool $granted = true): User
    {
        $role = Role::create(['name' => 'dueno', 'guard_name' => 'web']);

        if ($granted) {
            $role->givePermissionTo(Permission::firstOrCreate([
                'name' => CustomPermission::CalculateTithe->value,
                'guard_name' => 'web',
            ]));
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function saleOf(float $amount): void
    {
        $sale = Sale::factory()->forEstablishment($this->matriz)->create([
            'date' => '2026-09-10',
            'status' => SaleStatus::Completada,
            'subtotal' => $amount,
            'total' => $amount,
        ]);

        SaleItem::factory()->forSale($sale)->create([
            'product_id' => null,
            'description' => 'Servicio técnico',
            'quantity' => 1,
            'unit_price' => $amount,
            'unit_cost' => 0,
            'subtotal' => $amount,
            'total' => $amount,
        ]);
    }

    public function test_sin_permiso_no_se_ve(): void
    {
        $this->actingAs($this->userWithTithePermission(granted: false));

        $this->assertFalse(TitheWidget::canView());
    }

    public function test_con_permiso_se_ve_el_mes_sin_calcular(): void
    {
        $this->actingAs($this->userWithTithePermission());

        $this->assertTrue(TitheWidget::canView());
        Livewire::test(TitheWidget::class)
            ->assertSee('Septiembre 2026')
            ->assertSee('Sin calcular')
            ->assertSee('Sacar diezmo del mes');
    }

    public function test_el_modal_guarda_el_diezmo_con_los_pagos_agregados(): void
    {
        $this->actingAs($this->userWithTithePermission());
        $this->saleOf(5000);

        Livewire::test(TitheWidget::class)
            ->mountAction('calculateTithe')
            ->setActionData([
                'period' => '2026-09',
                'deductions' => [
                    'a' => ['concept' => 'Pago de empleados', 'amount' => 2000],
                ],
                'notes' => null,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Diezmo de Septiembre 2026: L 300.00');

        $tithe = Tithe::query()->sole();
        $this->assertSame(9, $tithe->month);
        $this->assertEquals(300.00, (float) $tithe->amount);
        $this->assertSame(['Pago de empleados'], $tithe->deductions()->pluck('concept')->all());
    }

    public function test_al_abrir_el_modal_carga_los_pagos_ya_guardados_del_mes(): void
    {
        $this->actingAs($this->userWithTithePermission());
        $tithe = Tithe::factory()->forMonth(2026, 9)->create();
        TitheDeduction::factory()->for($tithe)->create(['concept' => 'Planilla', 'amount' => 750]);

        $component = Livewire::test(TitheWidget::class)
            ->mountAction('calculateTithe');

        $deductions = array_values($component->instance()->mountedActions[0]['data']['deductions'] ?? []);

        $this->assertSame('Planilla', $deductions[0]['concept'] ?? null);
        $this->assertEquals(750, (float) ($deductions[0]['amount'] ?? 0));
    }

    public function test_un_pago_negativo_no_se_guarda(): void
    {
        $this->actingAs($this->userWithTithePermission());

        Livewire::test(TitheWidget::class)
            ->callAction('calculateTithe', data: [
                'period' => '2026-09',
                'deductions' => [
                    'a' => ['concept' => 'Error', 'amount' => -50],
                ],
            ])
            ->assertHasActionErrors();

        $this->assertSame(0, Tithe::count());
    }
}

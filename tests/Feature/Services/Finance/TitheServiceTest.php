<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Finance;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tithe;
use App\Models\User;
use App\Services\Finance\Exceptions\MesDiezmoInvalidoException;
use App\Services\Finance\TitheService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * TitheService: guarda un diezmo por mes con la foto de las cifras.
 */
class TitheServiceTest extends TestCase
{
    use CreatesMatriz, RefreshDatabase;

    private TitheService $service;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-30 10:00:00');
        \Carbon\Carbon::setTestNow('2026-09-30 10:00:00');

        $this->actingAs(User::factory()->create());
        $this->service = app(TitheService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Servicio de 2,000 sin costo → utilidad neta 2,000.
     */
    private function saleOf(float $amount, string $date = '2026-09-10'): void
    {
        $sale = Sale::factory()->forEstablishment($this->matriz)->create([
            'date' => $date,
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

    public function test_guarda_el_diezmo_con_la_foto_de_las_cifras_y_sus_pagos(): void
    {
        $this->saleOf(2000);

        $this->service->save(2026, 9, [
            ['concept' => 'Pago de empleados', 'amount' => 500],
            ['concept' => ' Alquiler bodega ', 'amount' => '300.50'],
        ], 'Diezmo de septiembre');

        $tithe = $this->service->forMonth(2026, 9);

        $this->assertNotNull($tithe);
        $this->assertEquals(2000.00, (float) $tithe->net_profit);
        $this->assertEquals(800.50, (float) $tithe->extra_deductions);
        $this->assertEquals(1199.50, (float) $tithe->base_amount);
        $this->assertEquals(119.95, (float) $tithe->amount);
        $this->assertEquals(0.10, (float) $tithe->rate);
        $this->assertSame('Diezmo de septiembre', $tithe->notes);
        $this->assertSame(['Pago de empleados', 'Alquiler bodega'], $tithe->deductions->pluck('concept')->all());
        $this->assertSame('Septiembre 2026', $tithe->periodLabel());
    }

    public function test_recalcular_el_mismo_mes_lo_actualiza_sin_duplicar(): void
    {
        $this->saleOf(2000);
        $this->service->save(2026, 9, [['concept' => 'Pago de empleados', 'amount' => 500]]);

        // Entra otra venta y se recalcula sin pagos.
        $this->saleOf(1000);
        $this->service->save(2026, 9, []);

        $this->assertSame(1, Tithe::count());
        $tithe = $this->service->forMonth(2026, 9);
        $this->assertEquals(3000.00, (float) $tithe->net_profit);
        $this->assertEquals(300.00, (float) $tithe->amount);
        $this->assertCount(0, $tithe->deductions);
    }

    public function test_cada_mes_tiene_su_propio_diezmo(): void
    {
        $this->saleOf(1000, '2026-08-20');
        $this->saleOf(2000, '2026-09-10');

        $this->service->save(2026, 8, []);
        $this->service->save(2026, 9, []);

        $this->assertEquals(100.00, (float) $this->service->forMonth(2026, 8)->amount);
        $this->assertEquals(200.00, (float) $this->service->forMonth(2026, 9)->amount);
    }

    public function test_no_se_puede_sacar_el_diezmo_de_un_mes_que_no_empieza(): void
    {
        $this->expectException(MesDiezmoInvalidoException::class);

        $this->service->save(2026, 10, []);
    }

    public function test_un_mes_sin_ganancia_guarda_diezmo_cero(): void
    {
        $this->saleOf(300);

        $this->service->save(2026, 9, [['concept' => 'Pago de empleados', 'amount' => 1000]]);

        $tithe = $this->service->forMonth(2026, 9);
        $this->assertEquals(-700.00, (float) $tithe->base_amount);
        $this->assertEquals(0.00, (float) $tithe->amount);
    }
}

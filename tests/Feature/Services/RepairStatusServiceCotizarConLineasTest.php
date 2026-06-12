<?php

namespace Tests\Feature\Services;

use App\Enums\RepairItemCondition;
use App\Enums\RepairItemSource;
use App\Enums\RepairLogEvent;
use App\Enums\RepairStatus;
use App\Enums\TaxType;
use App\Exceptions\Repairs\RepairTransitionException;
use App\Models\Repair;
use App\Models\RepairItem;
use App\Models\User;
use App\Services\Repairs\RepairStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de la cotización rápida (`cotizarConLineas`).
 *
 * Este método alimenta el modal "Marcar como Cotizado" del listado:
 * diagnóstico + líneas + transición en una sola transacción. Lo crítico
 * es la atomicidad — si la transición falla, las líneas recién agregadas
 * deben hacer rollback y el Repair queda intacto.
 */
class RepairStatusServiceCotizarConLineasTest extends TestCase
{
    use RefreshDatabase;

    private RepairStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RepairStatusService::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_cotiza_con_lineas_y_diagnostico_en_un_solo_paso(): void
    {
        $repair = Repair::factory()->create(); // Recibido, sin diagnóstico, sin líneas

        $updated = $this->service->cotizarConLineas(
            repair: $repair,
            lines: [
                [
                    'source' => RepairItemSource::HonorariosReparacion,
                    'description' => 'Honorarios por reparación',
                    'quantity' => 1,
                    'unit_price' => 500.00,
                ],
                [
                    'source' => RepairItemSource::PiezaExterna,
                    'condition' => RepairItemCondition::Nueva,
                    'description' => 'Pantalla 14" nueva',
                    'quantity' => 1,
                    'unit_price' => 1150.00, // CON ISV → base 1000 + 150 ISV
                ],
            ],
            diagnosis: 'Pantalla quebrada, requiere reemplazo.',
            note: 'Cotización válida 7 días.',
        );

        $this->assertSame(RepairStatus::Cotizado, $updated->status);
        $this->assertNotNull($updated->quoted_at);
        $this->assertSame('Pantalla quebrada, requiere reemplazo.', $updated->diagnosis);
        $this->assertCount(2, $updated->items);

        // Totales recalculados: honorarios exentos 500 + pieza nueva 1150 (base 1000 + ISV 150)
        $this->assertEquals(1500.00, (float) $updated->subtotal);
        $this->assertEquals(500.00, (float) $updated->exempt_total);
        $this->assertEquals(1000.00, (float) $updated->taxable_total);
        $this->assertEquals(150.00, (float) $updated->isv);
        $this->assertEquals(1650.00, (float) $updated->total);

        // Log de transición registrado
        $this->assertTrue(
            $updated->statusLogs()
                ->where('event_type', RepairLogEvent::StatusChange->value)
                ->where('to_status', RepairStatus::Cotizado->value)
                ->exists()
        );
    }

    public function test_rollback_total_si_falta_diagnostico(): void
    {
        $repair = Repair::factory()->create(); // diagnosis null

        try {
            $this->service->cotizarConLineas(
                repair: $repair,
                lines: [[
                    'source' => RepairItemSource::HonorariosReparacion,
                    'description' => 'Honorarios',
                    'quantity' => 1,
                    'unit_price' => 300.00,
                ]],
                diagnosis: null, // no se provee → cotizar() debe fallar
            );
            $this->fail('Se esperaba DomainException por diagnóstico vacío.');
        } catch (\DomainException) {
            // esperado
        }

        // Atomicidad: las líneas del modal NO quedaron persistidas
        $fresh = $repair->fresh();
        $this->assertSame(RepairStatus::Recibido, $fresh->status);
        $this->assertSame(0, RepairItem::where('repair_id', $repair->id)->count());
        $this->assertEquals(0.00, (float) $fresh->total);
    }

    public function test_falla_sin_lineas(): void
    {
        $repair = Repair::factory()->create(['diagnosis' => 'Mantenimiento general.']);

        $this->expectException(\DomainException::class);

        $this->service->cotizarConLineas($repair, lines: [], diagnosis: null);
    }

    public function test_falla_si_el_estado_no_permite_cotizar(): void
    {
        $repair = Repair::factory()->quoted()->create();

        $this->expectException(RepairTransitionException::class);

        $this->service->cotizarConLineas($repair, lines: [[
            'source' => RepairItemSource::HonorariosReparacion,
            'description' => 'Honorarios',
            'quantity' => 1,
            'unit_price' => 300.00,
        ]]);
    }

    public function test_agrega_lineas_nuevas_sobre_las_existentes(): void
    {
        $repair = Repair::factory()->create(['diagnosis' => 'Diagnóstico previo.']);
        RepairItem::create([
            'repair_id' => $repair->id,
            'source' => RepairItemSource::HonorariosReparacion,
            'description' => 'Honorarios previos',
            'quantity' => 1,
            'unit_price' => 400.00,
            'tax_type' => TaxType::Exento,
            'subtotal' => 400.00,
            'isv_amount' => 0,
            'total' => 400.00,
        ]);
        $repair->update(['subtotal' => 400, 'exempt_total' => 400, 'total' => 400]);

        $updated = $this->service->cotizarConLineas($repair, lines: [[
            'source' => RepairItemSource::HonorariosMantenimiento,
            'description' => 'Limpieza interna',
            'quantity' => 1,
            'unit_price' => 200.00,
        ]]);

        $this->assertSame(RepairStatus::Cotizado, $updated->status);
        $this->assertCount(2, $updated->items);
        $this->assertEquals(600.00, (float) $updated->total);
    }

    public function test_lineas_vacias_es_valido_si_ya_hay_lineas(): void
    {
        $repair = Repair::factory()->create(['diagnosis' => 'Diagnóstico previo.']);
        RepairItem::create([
            'repair_id' => $repair->id,
            'source' => RepairItemSource::HonorariosReparacion,
            'description' => 'Honorarios',
            'quantity' => 1,
            'unit_price' => 350.00,
            'tax_type' => TaxType::Exento,
            'subtotal' => 350.00,
            'isv_amount' => 0,
            'total' => 350.00,
        ]);
        $repair->update(['subtotal' => 350, 'exempt_total' => 350, 'total' => 350]);

        $updated = $this->service->cotizarConLineas($repair, lines: []);

        $this->assertSame(RepairStatus::Cotizado, $updated->status);
        $this->assertCount(1, $updated->items);
    }
}

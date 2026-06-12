<?php

namespace Tests\Feature\Services;

use App\Enums\CashMovementType;
use App\Enums\RepairLogEvent;
use App\Enums\RepairStatus;
use App\Exceptions\Cash\NoHayCajaAbiertaException;
use App\Models\CashMovement;
use App\Models\Repair;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\Repairs\RepairStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Tests de `aprobar()` con auto-inicio de reparación.
 *
 * Decisión de negocio 2026-06-12: aprobada la cotización el trabajo
 * comienza de inmediato — aprobar encadena Cotizado→Aprobado→EnReparacion
 * en una sola transacción. Estos tests verifican la cadena completa,
 * la atomicidad con anticipo y que el historial conserva ambas
 * transiciones para auditoría.
 */
class RepairStatusServiceAprobarTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMatriz;

    private RepairStatusService $service;

    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RepairStatusService::class);
        $this->cajero = User::factory()->create();
        $this->actingAs($this->cajero);
    }

    public function test_aprobar_pasa_directo_a_en_reparacion(): void
    {
        $repair = Repair::factory()->quoted()->create([
            'establishment_id' => $this->matriz->id,
            'total' => 1000.00,
        ]);

        $updated = $this->service->aprobar($repair);

        $this->assertSame(RepairStatus::EnReparacion, $updated->status);
        $this->assertNotNull($updated->approved_at);
        $this->assertNotNull($updated->repair_started_at);

        // Historial completo: ambas transiciones registradas para auditoría
        $logs = $updated->statusLogs()
            ->where('event_type', RepairLogEvent::StatusChange->value)
            ->orderBy('id')
            ->get();

        // to_status está casteado a enum en el modelo — comparar enums
        $this->assertSame(RepairStatus::Aprobado, $logs[0]->to_status);
        $this->assertSame(RepairStatus::EnReparacion, $logs[1]->to_status);
    }

    public function test_aprobar_asigna_tecnico_si_no_habia(): void
    {
        $repair = Repair::factory()->quoted()->create([
            'establishment_id' => $this->matriz->id,
            'technician_id' => null,
            'total' => 500.00,
        ]);

        $updated = $this->service->aprobar($repair);

        $this->assertSame($this->cajero->id, $updated->technician_id);
    }

    public function test_aprobar_con_anticipo_registra_caja_y_auto_inicia(): void
    {
        app(CashSessionService::class)->open(
            establishmentId: $this->matriz->id,
            openedBy: $this->cajero,
            openingAmount: 1000.00,
        );

        $repair = Repair::factory()->quoted()->create([
            'establishment_id' => $this->matriz->id,
            'total' => 2000.00,
        ]);

        $updated = $this->service->aprobar($repair, advancePayment: 500.00);

        $this->assertSame(RepairStatus::EnReparacion, $updated->status);
        $this->assertEquals(500.00, (float) $updated->advance_payment);

        $this->assertTrue(
            CashMovement::where('type', CashMovementType::RepairAdvancePayment->value)
                ->where('reference_id', $repair->id)
                ->exists()
        );
    }

    public function test_aprobar_con_anticipo_sin_caja_hace_rollback_total(): void
    {
        $repair = Repair::factory()->quoted()->create([
            'establishment_id' => $this->matriz->id,
            'total' => 2000.00,
        ]);

        try {
            $this->service->aprobar($repair, advancePayment: 500.00);
            $this->fail('Se esperaba NoHayCajaAbiertaException.');
        } catch (NoHayCajaAbiertaException) {
            // esperado
        }

        // Atomicidad: ni aprobado ni en reparación ni anticipo persistido
        $fresh = $repair->fresh();
        $this->assertSame(RepairStatus::Cotizado, $fresh->status);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->repair_started_at);
        $this->assertEquals(0.00, (float) $fresh->advance_payment);
    }
}

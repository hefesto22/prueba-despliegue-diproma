<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Purchases;

use App\Enums\CashMovementType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseKind;
use App\Enums\PurchaseStatus;
use App\Exceptions\Cash\NoHayCajaAbiertaException;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Cash\CashBalanceCalculator;
use App\Services\Cash\CashSessionService;
use App\Services\Purchases\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * "Todo en Compras" (2026-09-28): Compras es quien saca el efectivo del cajón.
 *
 * Contratos:
 *   - Confirmar una compra en efectivo registra la salida en la caja abierta
 *     de su sucursal (Gasto si es gasto, Pago a proveedor si es mercadería),
 *     enlazada a la compra.
 *   - Sin caja abierta, la confirmación falla completa (la compra sigue en
 *     Borrador): el cierre de caja no cuadraría.
 *   - Otros métodos de pago no tocan la caja.
 *   - Anular una compra confirmada pagada del cajón devuelve el dinero a la
 *     caja abierta (también las migradas desde el módulo Gastos, cuya salida
 *     de caja quedó enlazada a la compra por la migración).
 */
class PurchaseCashPaymentTest extends TestCase
{
    use CreatesMatriz, RefreshDatabase;

    private PurchaseService $service;

    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PurchaseService::class);
        $this->cajero = User::factory()->create();
        $this->actingAs($this->cajero);
    }

    private function openCaja(float $openingAmount = 1000.00): CashSession
    {
        return app(CashSessionService::class)->open(
            establishmentId: $this->matriz->id,
            openedBy: $this->cajero,
            openingAmount: $openingAmount,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeGastoEnEfectivo(float $total = 150.00, array $attributes = []): Purchase
    {
        return Purchase::factory()
            ->forEstablishment($this->matriz)
            ->create([
                'kind' => PurchaseKind::Gasto,
                'expense_category' => ExpenseCategory::Combustible,
                'description' => 'Gasolina entrega',
                'payment_method' => PaymentMethod::Efectivo,
                'subtotal' => $total,
                'taxable_total' => 0,
                'exempt_total' => $total,
                'isv' => 0,
                'total' => $total,
                ...$attributes,
            ]);
    }

    // ─── Confirmar ───────────────────────────────────────────

    public function test_confirmar_gasto_en_efectivo_saca_el_dinero_de_la_caja_abierta(): void
    {
        $caja = $this->openCaja(1000.00);
        $gasto = $this->makeGastoEnEfectivo(150.00);

        $this->service->confirm($gasto);

        $movement = CashMovement::query()
            ->where('reference_type', Purchase::class)
            ->where('reference_id', $gasto->id)
            ->sole();

        $this->assertSame($caja->id, $movement->cash_session_id);
        $this->assertSame(CashMovementType::Expense, $movement->type);
        $this->assertSame(PaymentMethod::Efectivo, $movement->payment_method);
        $this->assertEquals(150.00, (float) $movement->amount);
        $this->assertSame(ExpenseCategory::Combustible, $movement->category);
        $this->assertStringContainsString($gasto->purchase_number, $movement->description);
        $this->assertStringContainsString('Gasolina entrega', $movement->description);

        $this->assertEquals(850.00, app(CashBalanceCalculator::class)->expectedCash($caja->fresh()));
    }

    public function test_confirmar_mercaderia_en_efectivo_se_registra_como_pago_a_proveedor(): void
    {
        $this->openCaja();
        $compra = Purchase::factory()
            ->forEstablishment($this->matriz)
            ->withTotals(taxable: 100.00)
            ->create(['payment_method' => PaymentMethod::Efectivo]);

        $this->service->confirm($compra);

        $movement = $compra->cashMovements()->sole();
        $this->assertSame(CashMovementType::SupplierPayment, $movement->type);
        $this->assertEquals(115.00, (float) $movement->amount);
        $this->assertNull($movement->category);
    }

    public function test_sin_caja_abierta_no_se_puede_confirmar_un_pago_en_efectivo(): void
    {
        $gasto = $this->makeGastoEnEfectivo();

        try {
            $this->service->confirm($gasto);
            $this->fail('Esperaba NoHayCajaAbiertaException.');
        } catch (NoHayCajaAbiertaException) {
            // OK
        }

        $this->assertSame(PurchaseStatus::Borrador, $gasto->fresh()->status,
            'La transacción completa hace rollback: la compra sigue en borrador.');
        $this->assertSame(0, CashMovement::count());
    }

    public function test_pago_que_no_es_efectivo_no_toca_la_caja(): void
    {
        $this->openCaja();
        $gasto = $this->makeGastoEnEfectivo(attributes: ['payment_method' => PaymentMethod::Transferencia]);

        $this->service->confirm($gasto);

        $this->assertSame(PurchaseStatus::Confirmada, $gasto->fresh()->status);
        $this->assertSame(0, $gasto->cashMovements()->count());
    }

    public function test_pago_que_no_es_efectivo_se_confirma_sin_caja_abierta(): void
    {
        $gasto = $this->makeGastoEnEfectivo(attributes: ['payment_method' => PaymentMethod::TarjetaCredito]);

        $this->service->confirm($gasto);

        $this->assertSame(PurchaseStatus::Confirmada, $gasto->fresh()->status);
    }

    // ─── Anular ──────────────────────────────────────────────

    public function test_anular_gasto_pagado_del_cajon_devuelve_el_dinero_a_la_caja(): void
    {
        $caja = $this->openCaja(1000.00);
        $gasto = $this->makeGastoEnEfectivo(150.00);
        $this->service->confirm($gasto);

        $this->service->cancel($gasto);

        $devolucion = $gasto->cashMovements()
            ->where('type', CashMovementType::PurchaseCancellation->value)
            ->sole();

        $this->assertSame($caja->id, $devolucion->cash_session_id);
        $this->assertEquals(150.00, (float) $devolucion->amount);
        $this->assertSame(PurchaseStatus::Anulada, $gasto->fresh()->status);
        $this->assertEquals(1000.00, app(CashBalanceCalculator::class)->expectedCash($caja->fresh()),
            'Salida + devolución: la caja vuelve al monto de apertura.');
    }

    public function test_anular_devuelve_a_la_caja_abierta_aunque_el_pago_saliera_de_otra_sesion(): void
    {
        $cajaDelPago = $this->openCaja();
        $gasto = $this->makeGastoEnEfectivo(80.00);
        $this->service->confirm($gasto);
        app(CashSessionService::class)->close(
            session: $cajaDelPago,
            closedBy: $this->cajero,
            actualClosingAmount: app(CashBalanceCalculator::class)->expectedCash($cajaDelPago->fresh()),
        );

        $cajaDeHoy = $this->openCaja();
        $this->service->cancel($gasto);

        $devolucion = $gasto->cashMovements()
            ->where('type', CashMovementType::PurchaseCancellation->value)
            ->sole();
        $this->assertSame($cajaDeHoy->id, $devolucion->cash_session_id);
    }

    public function test_anular_gasto_pagado_del_cajon_sin_caja_abierta_falla_y_no_anula(): void
    {
        $caja = $this->openCaja();
        $gasto = $this->makeGastoEnEfectivo();
        $this->service->confirm($gasto);
        app(CashSessionService::class)->close(
            session: $caja,
            closedBy: $this->cajero,
            actualClosingAmount: app(CashBalanceCalculator::class)->expectedCash($caja->fresh()),
        );

        $this->expectException(NoHayCajaAbiertaException::class);

        try {
            $this->service->cancel($gasto);
        } finally {
            $this->assertSame(PurchaseStatus::Confirmada, $gasto->fresh()->status);
        }
    }

    public function test_anular_compra_que_no_salio_del_cajon_no_toca_la_caja(): void
    {
        $gasto = $this->makeGastoEnEfectivo(attributes: ['payment_method' => PaymentMethod::Transferencia]);
        $this->service->confirm($gasto);

        // Sin caja abierta: no la necesita porque no hay efectivo que devolver.
        $this->service->cancel($gasto);

        $this->assertSame(PurchaseStatus::Anulada, $gasto->fresh()->status);
        $this->assertSame(0, CashMovement::count());
    }

    public function test_anular_gasto_migrado_devuelve_su_salida_de_caja_enlazada(): void
    {
        // Gasto migrado desde el módulo Gastos: ya confirmado, sin
        // payment_method nuevo, con su salida de caja original enlazada.
        $caja = $this->openCaja();
        $gasto = $this->makeGastoEnEfectivo(60.00, [
            'status' => PurchaseStatus::Confirmada,
            'payment_method' => null,
        ]);
        CashMovement::factory()->create([
            'cash_session_id' => $caja->id,
            'user_id' => $this->cajero->id,
            'type' => CashMovementType::Expense,
            'payment_method' => PaymentMethod::Efectivo,
            'amount' => 60.00,
            'reference_type' => Purchase::class,
            'reference_id' => $gasto->id,
        ]);

        $this->service->cancel($gasto);

        $this->assertEquals(60.00, (float) $gasto->cashMovements()
            ->where('type', CashMovementType::PurchaseCancellation->value)
            ->sum('amount'));
    }
}

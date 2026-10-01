<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Purchases;

use App\Enums\CashMovementType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseKind;
use App\Enums\PurchaseStatus;
use App\Models\CashMovement;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\Purchases\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Decisión 2026-09-29: registrar compras es solo registrar el documento. La
 * forma de pago es informativa; confirmar o anular nunca mueve la caja,
 * aunque se haya pagado en efectivo y haya una caja abierta.
 */
class PurchaseDoesNotTouchCashTest extends TestCase
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

    private function makeGastoEnEfectivo(): Purchase
    {
        return Purchase::factory()->forEstablishment($this->matriz)->create([
            'kind' => PurchaseKind::Gasto,
            'expense_category' => ExpenseCategory::Combustible,
            'description' => 'Gasolina entrega',
            'payment_method' => PaymentMethod::Efectivo,
            'subtotal' => 150,
            'exempt_total' => 150,
            'total' => 150,
        ]);
    }

    public function test_confirmar_y_anular_en_efectivo_no_crean_movimientos_de_caja(): void
    {
        app(CashSessionService::class)->open($this->matriz->id, $this->cajero, 1000.00);
        $gasto = $this->makeGastoEnEfectivo();

        $this->service->confirm($gasto);
        $this->service->cancel($gasto);

        $this->assertSame(PurchaseStatus::Anulada, $gasto->fresh()->status);
        $this->assertSame(
            [CashMovementType::OpeningBalance],
            CashMovement::query()->pluck('type')->all(),
            'Solo existe la apertura: la compra no tocó la caja.',
        );
    }

    public function test_se_confirma_en_efectivo_sin_caja_abierta(): void
    {
        $gasto = $this->makeGastoEnEfectivo();

        $this->service->confirm($gasto);

        $this->assertSame(PurchaseStatus::Confirmada, $gasto->fresh()->status);
    }

    public function test_anular_un_gasto_migrado_no_revierte_su_salida_historica(): void
    {
        // Gasto migrado del módulo Gastos: su salida de caja original quedó
        // enlazada a la compra. Anularlo no genera una devolución.
        $caja = app(CashSessionService::class)->open($this->matriz->id, $this->cajero, 1000.00);
        $gasto = $this->makeGastoEnEfectivo();
        $gasto->update(['status' => PurchaseStatus::Confirmada]);
        CashMovement::factory()->create([
            'cash_session_id' => $caja->id,
            'user_id' => $this->cajero->id,
            'type' => CashMovementType::Expense,
            'payment_method' => PaymentMethod::Efectivo,
            'amount' => 150.00,
            'reference_type' => Purchase::class,
            'reference_id' => $gasto->id,
        ]);

        $this->service->cancel($gasto);

        $this->assertSame(1, $gasto->cashMovements()->count());
    }
}

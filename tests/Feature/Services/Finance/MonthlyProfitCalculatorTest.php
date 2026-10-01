<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Finance;

use App\Enums\ExpenseCategory;
use App\Enums\PurchaseKind;
use App\Enums\PurchaseStatus;
use App\Enums\SaleStatus;
use App\Enums\SupplierDocumentType;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Finance\MonthlyProfitCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Utilidad de un mes cualquiera (la comparten el Escritorio y el diezmo).
 * Las reglas finas del costo (kardex, líneas sin producto) las cubre
 * DashboardStatsServiceTest; aquí se fija que el período sea el pedido.
 */
class MonthlyProfitCalculatorTest extends TestCase
{
    use CreatesMatriz, RefreshDatabase;

    /**
     * Venta de servicio (sin producto): ingreso y costo directos en la línea.
     */
    private function sale(string $date, float $revenue, float $cost, array $attributes = []): Sale
    {
        $sale = Sale::factory()->forEstablishment($this->matriz)->create([
            'date' => $date,
            'status' => SaleStatus::Completada,
            'subtotal' => $revenue,
            'total' => $revenue,
            ...$attributes,
        ]);

        SaleItem::factory()->forSale($sale)->create([
            'product_id' => null,
            'description' => 'Servicio técnico',
            'quantity' => 1,
            'unit_price' => $revenue,
            'unit_cost' => $cost,
            'subtotal' => $revenue,
            'total' => $revenue,
        ]);

        return $sale;
    }

    private function gasto(string $date, float $amount, PurchaseStatus $status = PurchaseStatus::Confirmada): Purchase
    {
        return Purchase::factory()->forEstablishment($this->matriz)->create([
            'kind' => PurchaseKind::Gasto,
            'expense_category' => ExpenseCategory::Otros,
            'status' => $status,
            'document_type' => SupplierDocumentType::ReciboInterno,
            'supplier_cai' => null,
            'date' => $date,
            'subtotal' => $amount,
            'exempt_total' => $amount,
            'total' => $amount,
        ]);
    }

    public function test_solo_cuenta_el_mes_pedido(): void
    {
        $this->sale('2026-08-01', 1000, 400, ['card_fee_amount' => 34]);
        $this->sale('2026-08-31', 500, 100);
        $this->gasto('2026-08-15', 150);

        // Otros meses y documentos que no cuentan.
        $this->sale('2026-07-31', 9999, 0, ['card_fee_amount' => 99]);
        $this->sale('2026-09-01', 9999, 0);
        $this->sale('2026-08-10', 9999, 0, ['status' => SaleStatus::Anulada]);
        $this->gasto('2026-07-31', 999);
        $this->gasto('2026-08-20', 999, PurchaseStatus::Borrador);

        $profit = app(MonthlyProfitCalculator::class)->forMonth(2026, 8);

        $this->assertSame(1500.00, $profit->revenue);
        $this->assertSame(500.00, $profit->cost);
        $this->assertSame(150.00, $profit->expensePurchases);
        $this->assertSame(34.00, $profit->cardFees);
        $this->assertSame(1000.00, $profit->grossProfit());
        $this->assertSame(816.00, $profit->netProfit());
    }
}

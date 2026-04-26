<?php

namespace Tests\Feature\Services;

use App\Enums\MovementType;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\TaxType;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Services\Purchases\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseService $service;
    private Supplier $supplier;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        // Resolver via container para que Laravel inyecte PurchaseTotalsCalculator.
        // Alternativa `new PurchaseService(new PurchaseTotalsCalculator())` funciona
        // pero acopla el test a la lista de dependencias — si se suman nuevas
        // todos los tests rompen.
        $this->service = app(PurchaseService::class);
        $this->supplier = Supplier::factory()->create();
        $this->category = Category::factory()->create();
    }

    /**
     * Helper: crear un producto con stock y costo conocidos.
     */
    private function makeProduct(float $costPrice = 1000, int $stock = 1): Product
    {
        return Product::factory()->inCategory($this->category)->create([
            'cost_price' => $costPrice,
            'stock' => $stock,
            'condition' => \App\Enums\ProductCondition::New,
            'tax_type' => TaxType::Gravado15,
        ]);
    }

    /**
     * Helper: crear compra borrador con items.
     */
    private function makePurchase(array $items): Purchase
    {
        $purchase = Purchase::factory()->fromSupplier($this->supplier)->create([
            'date' => now(),
            'status' => PurchaseStatus::Borrador,
        ]);

        foreach ($items as $item) {
            PurchaseItem::factory()->forPurchase($purchase)->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_cost' => $item['unit_cost'],
                'tax_type' => $item['tax_type'] ?? TaxType::Gravado15,
            ]);
        }

        $purchase->load('items');
        return $purchase;
    }

    // ─── Tests de confirmación ───────────────────────────────

    public function test_confirm_increments_stock(): void
    {
        $product = $this->makeProduct(costPrice: 1000, stock: 5);

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 1200],
        ]);

        $this->service->confirm($purchase);

        $product->refresh();
        $this->assertEquals(8, $product->stock);
    }

    /**
     * Caso exacto de Mauricio:
     * Tenía 1 unidad a L1,000 → compra 1 más a L1,500 → costo promedio = L1,250.
     */
    public function test_confirm_calculates_weighted_average_cost(): void
    {
        $product = $this->makeProduct(costPrice: 1000, stock: 1);

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1500],
        ]);

        $this->service->confirm($purchase);

        $product->refresh();
        $this->assertEquals(1250.00, (float) $product->cost_price);
        $this->assertEquals(2, $product->stock);
    }

    public function test_confirm_with_zero_stock_uses_new_cost_directly(): void
    {
        $product = $this->makeProduct(costPrice: 0, stock: 0);

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 2000],
        ]);

        $this->service->confirm($purchase);

        $product->refresh();
        $this->assertEquals(2000.00, (float) $product->cost_price);
        $this->assertEquals(5, $product->stock);
    }

    public function test_confirm_sets_status_to_confirmada(): void
    {
        $product = $this->makeProduct();

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1000],
        ]);

        $this->service->confirm($purchase);

        $purchase->refresh();
        $this->assertEquals(PurchaseStatus::Confirmada, $purchase->status);
    }

    public function test_confirm_recalculates_totals_with_isv(): void
    {
        $product = $this->makeProduct();

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 1150],
        ]);

        $this->service->confirm($purchase);

        $purchase->refresh();

        // Total = 1150 * 2 = 2300
        $this->assertEquals(2300.00, (float) $purchase->total);
        // ISV > 0 para gravados
        $this->assertGreaterThan(0, (float) $purchase->isv);
        // subtotal + isv = total
        $this->assertEquals(
            (float) $purchase->total,
            (float) $purchase->subtotal + (float) $purchase->isv
        );
    }

    public function test_confirm_fails_for_non_borrador(): void
    {
        $product = $this->makeProduct();
        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1000],
        ]);

        // Confirmar primero
        $this->service->confirm($purchase);

        // Intentar confirmar de nuevo
        $this->expectException(\InvalidArgumentException::class);
        $this->service->confirm($purchase->refresh());
    }

    public function test_confirm_fails_without_items(): void
    {
        $purchase = Purchase::factory()->fromSupplier($this->supplier)->create([
            'date' => now(),
            'status' => PurchaseStatus::Borrador,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->confirm($purchase);
    }

    // ─── Tests de anulación ──────────────────────────────────

    public function test_cancel_confirmed_purchase_reverses_stock(): void
    {
        $product = $this->makeProduct(costPrice: 1000, stock: 5);

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 1200],
        ]);

        $this->service->confirm($purchase);
        $product->refresh();
        $this->assertEquals(8, $product->stock);

        $this->service->cancel($purchase->refresh());

        $product->refresh();
        $this->assertEquals(5, $product->stock);
        $this->assertEquals(PurchaseStatus::Anulada, $purchase->refresh()->status);
    }

    public function test_cancel_borrador_does_not_affect_stock(): void
    {
        $product = $this->makeProduct(costPrice: 1000, stock: 5);

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 1200],
        ]);

        $this->service->cancel($purchase);

        $product->refresh();
        $this->assertEquals(5, $product->stock); // Sin cambio
        $this->assertEquals(PurchaseStatus::Anulada, $purchase->refresh()->status);
    }

    public function test_cancel_stock_never_goes_negative(): void
    {
        $product = $this->makeProduct(costPrice: 1000, stock: 0);

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 1000],
        ]);

        // Confirmar (stock: 0 → 5)
        $this->service->confirm($purchase);
        $product->refresh();
        $this->assertEquals(5, $product->stock);

        // Simular que se vendieron todas: stock = 0
        $product->update(['stock' => 0]);

        // Anular: no puede quedar negativo
        $this->service->cancel($purchase->refresh());
        $product->refresh();
        $this->assertEquals(0, $product->stock);
    }

    public function test_cancel_already_cancelled_fails(): void
    {
        $product = $this->makeProduct();
        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1000],
        ]);

        $this->service->cancel($purchase);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->cancel($purchase->refresh());
    }

    // ─── Tests de costo promedio con múltiples compras ───────

    public function test_weighted_average_with_multiple_purchases(): void
    {
        // Stock inicial: 10 unidades a L800
        $product = $this->makeProduct(costPrice: 800, stock: 10);

        // Compra 1: 5 unidades a L1,000
        $purchase1 = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 1000],
        ]);
        $this->service->confirm($purchase1);

        $product->refresh();
        // Promedio: (10*800 + 5*1000) / 15 = (8000 + 5000) / 15 = 866.67
        $this->assertEquals(866.67, (float) $product->cost_price);
        $this->assertEquals(15, $product->stock);

        // Compra 2: 5 unidades a L1,200
        $purchase2 = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 1200],
        ]);
        $this->service->confirm($purchase2);

        $product->refresh();
        // Promedio: (15*866.67 + 5*1200) / 20 = (13000.05 + 6000) / 20 = 950.00
        $this->assertEquals(950.00, (float) $product->cost_price);
        $this->assertEquals(20, $product->stock);
    }

    // ─── Test: el historial de compras conserva el costo original ──

    // ─── Tests de snapshot de unit_cost en kardex ──────────────

    /**
     * Al confirmar la compra, el movimiento de kardex debe capturar
     * el unit_cost EXACTO del purchase_item — no el cost_price del
     * producto (que ya fue afectado por el cálculo de promedio ponderado).
     */
    public function test_confirm_captures_unit_cost_snapshot_from_purchase_item(): void
    {
        // Producto con costo previo distinto al de esta compra
        $product = $this->makeProduct(costPrice: 1000, stock: 10);

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 1500],
        ]);

        $this->service->confirm($purchase);

        $movement = InventoryMovement::where('product_id', $product->id)
            ->where('reference_type', Purchase::class)
            ->where('reference_id', $purchase->id)
            ->where('type', MovementType::EntradaCompra)
            ->first();

        $this->assertNotNull($movement);
        // El snapshot es el unit_cost de la compra (1500), NO el promedio ponderado
        $this->assertEquals(1500.00, (float) $movement->unit_cost);

        // Y verifico que el promedio sí cambió (para asegurar que el test sería
        // falso positivo si alguien usara cost_price del producto)
        $product->refresh();
        $this->assertNotEquals(1500.00, (float) $product->cost_price);
    }

    /**
     * Al anular una compra confirmada, el movimiento de reversión debe
     * usar el mismo unit_cost — garantiza que el valor total del kardex
     * queda en cero (entrada + salida = 0) para efectos contables.
     */
    public function test_cancel_purchase_creates_reversal_movement_with_same_unit_cost(): void
    {
        $product = $this->makeProduct(costPrice: 1000, stock: 5);

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 1200],
        ]);

        $this->service->confirm($purchase);
        $this->service->cancel($purchase->refresh());

        $reversal = InventoryMovement::where('product_id', $product->id)
            ->where('reference_type', Purchase::class)
            ->where('reference_id', $purchase->id)
            ->where('type', MovementType::SalidaAnulacionCompra)
            ->first();

        $this->assertNotNull($reversal);
        $this->assertEquals(1200.00, (float) $reversal->unit_cost);
    }

    public function test_purchase_item_preserves_original_cost(): void
    {
        $product = $this->makeProduct(costPrice: 1000, stock: 1);

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1500],
        ]);

        $this->service->confirm($purchase);

        // El producto tiene costo promedio
        $product->refresh();
        $this->assertEquals(1250.00, (float) $product->cost_price);

        // Pero el item de compra conserva el costo original de L1,500
        $item = $purchase->items->first();
        $this->assertEquals(1500.00, (float) $item->unit_cost);
    }

    // ─── payment_status: ciclo de vida ────────────────────────
    //
    // Regla de dominio (post 2026-04-25):
    //  - Borrador  → payment_status = Pendiente (siempre, no importa si es contado)
    //  - Confirmar → si credit_days = 0, pasa a Pagada en la misma transacción
    //                que afecta stock; si credit_days > 0, queda Pendiente para CxP
    //  - Anular    → payment_status NO se modifica (preserva el histórico)

    public function test_purchase_borrador_recien_creada_queda_pendiente_de_pago(): void
    {
        // Una compra recién creada en Borrador NO debe marcarse como Pagada
        // automáticamente, aunque sea contado. Hasta confirmarse no es operación
        // ejecutada.
        $purchase = Purchase::factory()->fromSupplier($this->supplier)->create([
            'date' => now(),
            'status' => PurchaseStatus::Borrador,
            'credit_days' => 0,
        ]);

        $this->assertEquals(
            PaymentStatus::Pendiente,
            $purchase->payment_status,
            'Una compra recién creada en Borrador debe quedar Pendiente — el pago se ejecuta al confirmar.'
        );
    }

    public function test_confirm_marca_pagada_para_compra_al_contado(): void
    {
        $product = $this->makeProduct();
        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100],
        ]);

        // Pre-condición: el borrador empieza Pendiente.
        $this->assertEquals(PaymentStatus::Pendiente, $purchase->payment_status);

        $this->service->confirm($purchase);
        $purchase->refresh();

        $this->assertEquals(PurchaseStatus::Confirmada, $purchase->status);
        $this->assertEquals(
            PaymentStatus::Pagada,
            $purchase->payment_status,
            'Confirmar una compra contado debe marcar payment_status como Pagada.'
        );
    }

    public function test_confirm_credito_mantiene_payment_status_pendiente(): void
    {
        // Cuando se implemente CxP, una compra a crédito se confirma pero
        // queda Pendiente hasta que se registre el pago. El service ya
        // respeta la regla — este test la fija por contrato.
        $product = $this->makeProduct();
        $purchase = Purchase::factory()->fromSupplier($this->supplier)->create([
            'date' => now(),
            'status' => PurchaseStatus::Borrador,
            'credit_days' => 30,
        ]);
        PurchaseItem::factory()->forPurchase($purchase)->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_cost' => 100,
            'tax_type' => TaxType::Gravado15,
        ]);
        $purchase->load('items');

        $this->service->confirm($purchase);
        $purchase->refresh();

        $this->assertEquals(PurchaseStatus::Confirmada, $purchase->status);
        $this->assertEquals(
            PaymentStatus::Pendiente,
            $purchase->payment_status,
            'A crédito el pago queda Pendiente hasta que CxP registre el pago.'
        );
    }

    public function test_cancel_no_modifica_payment_status_historico(): void
    {
        // Anular preserva el payment_status de la compra al momento de anular.
        // El dinero ya se entregó al proveedor (en contado) — el "deshacer"
        // operativo del stock no implica reembolso automático del pago.
        $product = $this->makeProduct();
        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100],
        ]);

        $this->service->confirm($purchase);
        $purchase->refresh();
        $this->assertEquals(PaymentStatus::Pagada, $purchase->payment_status);

        $this->service->cancel($purchase);
        $purchase->refresh();

        $this->assertEquals(PurchaseStatus::Anulada, $purchase->status);
        $this->assertEquals(
            PaymentStatus::Pagada,
            $purchase->payment_status,
            'Anular una compra confirmada no debe modificar el payment_status — preserva el histórico del pago real.'
        );
    }

    // ─── Costo Promedio Ponderado Móvil: solo stock disponible ──
    //
    // Regla de dominio: el CPP se recalcula usando el stock disponible al
    // momento de la nueva compra, NO el histórico de unidades que ya salieron
    // del inventario. Las unidades vendidas se descontaron del stock al costo
    // vigente en su momento — no participan en futuros recálculos del CPP.
    //
    // Este es el método estándar de "promedio móvil" (Moving Weighted Average
    // Cost), usado por SAP, Odoo, Bind, Alegra y la mayoría de ERPs comerciales.

    public function test_cpp_solo_considera_stock_disponible_no_unidades_ya_vendidas(): void
    {
        // Escenario realista:
        //   1. Tengo 1 unidad a L 2,500 (CPP histórico).
        //   2. Compro 1 unidad a L 2,000 → CPP debe ser (1×2500 + 1×2000) / 2 = 2,250.
        //   3. Vendo 1 unidad (stock baja a 1, CPP se mantiene en 2,250).
        //   4. Compro 2 unidades a L 2,300 → CPP debe ser (1×2250 + 2×2300) / 3 = 2,283.33.
        //
        // Lo crítico: en el paso 4, la unidad vendida en el paso 3 NO participa
        // del cálculo. Solo se promedian las unidades efectivamente disponibles
        // (1 a CPP 2,250) con las que entran (2 a costo 2,300).

        $product = $this->makeProduct(costPrice: 2500, stock: 1);

        // ── Paso 1: Compra de 1 unidad a L 2,000 ───────────────────
        $purchase1 = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 2000],
        ]);
        $this->service->confirm($purchase1);
        $product->refresh();

        $this->assertEquals(2250.00, (float) $product->cost_price,
            'Tras compra: CPP = (1×2500 + 1×2000) / 2 = 2,250');
        $this->assertEquals(2, $product->stock,
            'Tras compra: stock incrementa a 2 unidades');

        // ── Paso 2: Simular venta de 1 unidad ──────────────────────
        // No invocamos SaleService aquí porque este test se enfoca en el CPP
        // del PurchaseService. Bajamos el stock manualmente para simular el
        // efecto neto de una venta — el CPP NO debe cambiar al vender.
        $product->update(['stock' => 1]);
        $product->refresh();

        $this->assertEquals(2250.00, (float) $product->cost_price,
            'Tras venta: el CPP NO se mueve — las unidades restantes ya tenían ese costo');

        // ── Paso 3: Compra de 2 unidades a L 2,300 ──────────────────
        $purchase2 = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 2300],
        ]);
        $this->service->confirm($purchase2);
        $product->refresh();

        // Cálculo esperado: (stock_disponible × CPP + qty_compra × costo_compra) / (stock_disponible + qty_compra)
        //                 = (1 × 2250 + 2 × 2300) / (1 + 2)
        //                 = (2250 + 4600) / 3
        //                 = 6850 / 3
        //                 = 2,283.33 (redondeo a 2 decimales)
        $this->assertEquals(2283.33, (float) $product->cost_price,
            'CPP debe usar SOLO el stock disponible (1 unidad a 2,250) — la unidad vendida no participa');
        $this->assertEquals(3, $product->stock,
            'Stock final: 1 disponible + 2 nuevas = 3 unidades');
    }
}

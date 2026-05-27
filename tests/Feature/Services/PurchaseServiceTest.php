<?php

namespace Tests\Feature\Services;

use App\Enums\MovementType;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
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

/**
 * ─── Convención de costos (en estos tests) ──────────────────────────────────
 *
 * - `Product.cost_price` SIEMPRE almacena el NETO (sin ISV).
 * - `PurchaseItem.unit_cost` se ingresa según el documento:
 *     · Factura + Gravado15 → CON ISV incluido (back-out al confirmar)
 *     · Recibo Interno o producto Exento → SIN ISV (sin back-out)
 *
 * Para evitar ruido de redondeo en los tests, los valores con ISV son
 * múltiplos limpios de 1.15: 1,150 ↔ 1,000 NETO; 2,300 ↔ 2,000 NETO; etc.
 */
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
     * Helper: crear un producto con stock y costo NETO conocidos.
     */
    private function makeProduct(
        float $costPrice = 1000,
        int $stock = 1,
        TaxType $taxType = TaxType::Gravado15,
    ): Product {
        return Product::factory()->inCategory($this->category)->create([
            'cost_price' => $costPrice,
            'stock' => $stock,
            'condition' => $taxType === TaxType::Exento
                ? \App\Enums\ProductCondition::Used
                : \App\Enums\ProductCondition::New,
            'tax_type' => $taxType,
        ]);
    }

    /**
     * Helper: crear compra borrador con items.
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_cost: float, tax_type?: TaxType}>  $items
     * @param  SupplierDocumentType  $documentType  Tipo de documento del proveedor (Factura por default).
     */
    private function makePurchase(
        array $items,
        SupplierDocumentType $documentType = SupplierDocumentType::Factura,
    ): Purchase {
        $purchase = Purchase::factory()->fromSupplier($this->supplier)->create([
            'date' => now(),
            'status' => PurchaseStatus::Borrador,
            'document_type' => $documentType,
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
            ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 1380], // 1200 NETO con ISV
        ]);

        $this->service->confirm($purchase);

        $product->refresh();
        $this->assertEquals(8, $product->stock);
    }

    /**
     * Caso clásico de Mauricio (post-Opción A):
     * Tenía 1 unidad a L1,000 NETO → compra 1 más a L1,725 c/ISV (= L1,500 NETO).
     * CPP = (1×1000 + 1×1500) / 2 = L1,250 NETO.
     */
    public function test_confirm_calculates_weighted_average_cost_with_isv_backout(): void
    {
        $product = $this->makeProduct(costPrice: 1000, stock: 1);

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1725], // 1500 NETO con ISV
        ]);

        $this->service->confirm($purchase);

        $product->refresh();
        $this->assertEquals(1250.00, (float) $product->cost_price);
        $this->assertEquals(2, $product->stock);
    }

    public function test_confirm_with_zero_stock_uses_new_net_cost_directly(): void
    {
        $product = $this->makeProduct(costPrice: 0, stock: 0);

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 2300], // 2000 NETO con ISV
        ]);

        $this->service->confirm($purchase);

        $product->refresh();
        $this->assertEquals(2000.00, (float) $product->cost_price);
        $this->assertEquals(5, $product->stock);
    }

    /**
     * Recibo Interno: el unit_cost ingresado ES el costo final efectivo. NO se
     * hace back-out — el proveedor informal no emite factura SAR, no hay ISV
     * deducible. El L1,000 entra completo al CPP como NETO real.
     */
    public function test_confirm_recibo_interno_does_not_apply_backout(): void
    {
        $product = $this->makeProduct(costPrice: 0, stock: 0);

        $purchase = $this->makePurchase(
            items: [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1000],
            ],
            documentType: SupplierDocumentType::ReciboInterno,
        );

        $this->service->confirm($purchase);

        $product->refresh();
        $this->assertEquals(1000.00, (float) $product->cost_price,
            'En Recibo Interno el unit_cost ingresado es el costo NETO directo, sin back-out.');
    }

    /**
     * Producto Exento (Usado): aunque venga en Factura, no hay ISV que separar.
     * El unit_cost es directamente el costo NETO.
     */
    public function test_confirm_exento_product_in_factura_does_not_apply_backout(): void
    {
        $product = $this->makeProduct(
            costPrice: 0,
            stock: 0,
            taxType: TaxType::Exento,
        );

        $purchase = $this->makePurchase([
            [
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_cost' => 1000,
                'tax_type' => TaxType::Exento,
            ],
        ]);

        $this->service->confirm($purchase);

        $product->refresh();
        $this->assertEquals(1000.00, (float) $product->cost_price,
            'Producto Exento no separa ISV; el unit_cost ES el costo NETO.');
    }

    public function test_confirm_sets_status_to_confirmada(): void
    {
        $product = $this->makeProduct();

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1150],
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
        // ISV > 0 para gravados con factura
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
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1150],
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
            ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 1380],
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
            ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 1380],
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
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 1150],
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
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1150],
        ]);

        $this->service->cancel($purchase);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->cancel($purchase->refresh());
    }

    // ─── Tests de costo promedio con múltiples compras ───────

    public function test_weighted_average_with_multiple_purchases(): void
    {
        // Stock inicial: 10 unidades a L800 NETO
        $product = $this->makeProduct(costPrice: 800, stock: 10);

        // Compra 1: 5 unidades a L1,150 c/ISV (= L1,000 NETO)
        $purchase1 = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 1150],
        ]);
        $this->service->confirm($purchase1);

        $product->refresh();
        // Promedio: (10*800 + 5*1000) / 15 = 13000/15 = 866.6666... → round(4) = 866.6667
        // (Precisión interna 4 decimales para no acumular drift en compras sucesivas;
        // el display al usuario aplica round(_, 2) al final.)
        $this->assertEquals(866.6667, (float) $product->cost_price);
        $this->assertEquals(15, $product->stock);

        // Compra 2: 5 unidades a L1,380 c/ISV (= L1,200 NETO)
        $purchase2 = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 1380],
        ]);
        $this->service->confirm($purchase2);

        $product->refresh();
        // Promedio: (15 × 866.6667 + 5 × 1200) / 20 = (13000.0005 + 6000) / 20 = 950.000025
        // → round(4) = 950.0000. La precisión de 4 decimales del paso anterior
        // ya NO contamina el resultado final — preserva exactitud, no la pierde.
        $this->assertEquals(950.00, (float) $product->cost_price);
        $this->assertEquals(20, $product->stock);
    }

    // ─── Tests de snapshot de unit_cost en kardex ──────────────

    /**
     * Al confirmar la compra, el movimiento de kardex debe capturar el
     * unit_cost NETO derivado (no el unit_cost crudo del item ni el
     * cost_price post-CPP del producto).
     *
     * Esto mantiene el kardex consistente: salidas de venta capturan NETO
     * desde Product.cost_price, entradas de compra capturan NETO desde el
     * back-out del item. Sumar movimientos da valor de inventario en NETO.
     */
    public function test_confirm_captures_net_unit_cost_in_kardex(): void
    {
        // Producto con costo previo distinto al de esta compra
        $product = $this->makeProduct(costPrice: 1000, stock: 10);

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 1725], // 1500 NETO
        ]);

        $this->service->confirm($purchase);

        $movement = InventoryMovement::where('product_id', $product->id)
            ->where('reference_type', Purchase::class)
            ->where('reference_id', $purchase->id)
            ->where('type', MovementType::EntradaCompra)
            ->first();

        $this->assertNotNull($movement);
        // Snapshot NETO: 1725 / 1.15 = 1500.00 — el ISV no entra al kardex
        $this->assertEquals(1500.00, (float) $movement->unit_cost,
            'El kardex debe capturar el unit_cost NETO, no el WITH-ISV del item.');

        // Sanity: el item conserva el unit_cost crudo (lo que el operador
        // tipeó), solo se transforma de cara al producto y al kardex.
        $item = $purchase->items->first();
        $this->assertEquals(1725.00, (float) $item->unit_cost,
            'PurchaseItem.unit_cost preserva el valor crudo ingresado por el operador.');
    }

    /**
     * Al anular una compra confirmada, la salida del kardex usa el mismo
     * NETO que la entrada original. Así entrada + salida cuadran a cero
     * en el libro auxiliar de inventario.
     */
    public function test_cancel_purchase_creates_reversal_movement_with_same_net_unit_cost(): void
    {
        $product = $this->makeProduct(costPrice: 1000, stock: 5);

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 1380], // 1200 NETO
        ]);

        $this->service->confirm($purchase);
        $this->service->cancel($purchase->refresh());

        $reversal = InventoryMovement::where('product_id', $product->id)
            ->where('reference_type', Purchase::class)
            ->where('reference_id', $purchase->id)
            ->where('type', MovementType::SalidaAnulacionCompra)
            ->first();

        $this->assertNotNull($reversal);
        $this->assertEquals(1200.00, (float) $reversal->unit_cost,
            'La reversa de kardex debe usar el mismo NETO que la entrada original.');
    }

    public function test_purchase_item_preserves_original_cost(): void
    {
        $product = $this->makeProduct(costPrice: 1000, stock: 1);

        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1725], // 1500 NETO
        ]);

        $this->service->confirm($purchase);

        // El producto tiene CPP en NETO: (1×1000 + 1×1500)/2 = 1250
        $product->refresh();
        $this->assertEquals(1250.00, (float) $product->cost_price);

        // Pero el item de compra conserva el costo crudo de L1,725 (con ISV)
        $item = $purchase->items->first();
        $this->assertEquals(1725.00, (float) $item->unit_cost);
    }

    // ─── Escenario completo: el caso real de Mauricio ──────────
    //
    // 1. Registra inventario inicial: 1 unidad a L1,000 (sin factura).
    //    Resultado: cost_price = 1000 NETO, stock = 1, sin crédito fiscal.
    // 2. Compra 1 unidad a L1,150 c/ISV (Factura).
    //    Resultado:
    //      - cost_price = (1000 + 1000)/2 = 1000 NETO (CPP móvil)
    //      - stock = 2
    //      - purchases.isv = 150 (crédito fiscal del período)
    //      - kardex captura NETO 1000 en la entrada
    //
    // Lo importante: el crédito fiscal vive en `purchases.isv`, NO se
    // mezcla con el costo del inventario. El L150 es un activo tributario
    // que se compensa contra ISV cobrado en ventas en la declaración SAR.

    public function test_escenario_inventario_inicial_mas_compra_con_factura(): void
    {
        // Paso 1: inventario inicial (simulado vía factory — equivale al form de Producto)
        $product = $this->makeProduct(costPrice: 1000, stock: 1);

        // Paso 2: compra con Factura, unit_cost con ISV incluido
        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1150],
        ]);
        $this->service->confirm($purchase);

        // Verificar costo del inventario
        $product->refresh();
        $this->assertEquals(1000.00, (float) $product->cost_price,
            'CPP = (1×1000 + 1×1000) / 2 = 1000. La unidad inicial entró NETO 1000; la unidad comprada hizo back-out de 1150 a 1000.');
        $this->assertEquals(2, $product->stock);

        // Verificar crédito fiscal del período
        $purchase->refresh();
        $this->assertEquals(150.00, (float) $purchase->isv,
            'Solo la compra genera crédito fiscal (1150 - 1000 = 150). La carga inicial NO aporta nada.');
        $this->assertEquals(1000.00, (float) $purchase->subtotal);
        $this->assertEquals(1150.00, (float) $purchase->total);

        // Verificar kardex NETO
        $movement = InventoryMovement::where('product_id', $product->id)
            ->where('reference_type', Purchase::class)
            ->where('type', MovementType::EntradaCompra)
            ->first();
        $this->assertEquals(1000.00, (float) $movement->unit_cost);
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
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 115],
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
        $product = $this->makeProduct();
        $purchase = Purchase::factory()->fromSupplier($this->supplier)->create([
            'date' => now(),
            'status' => PurchaseStatus::Borrador,
            'credit_days' => 30,
        ]);
        PurchaseItem::factory()->forPurchase($purchase)->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_cost' => 115,
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
        $product = $this->makeProduct();
        $purchase = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 115],
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

    // ─── CPP móvil: solo stock disponible ──────────────────────────
    //
    // Regla: el CPP se recalcula usando el stock disponible al momento de la
    // nueva compra, NO el histórico de unidades que ya salieron del inventario.
    // Las unidades vendidas se descontaron del stock al costo vigente en su
    // momento — no participan en futuros recálculos del CPP.

    public function test_cpp_solo_considera_stock_disponible_no_unidades_ya_vendidas(): void
    {
        // Escenario realista (todos los costos en NETO/equivalente):
        //   1. Tengo 1 unidad a L 2,500 NETO (CPP histórico).
        //   2. Compro 1 unidad a L 2,300 c/ISV (= L 2,000 NETO) → CPP = (2500+2000)/2 = 2,250.
        //   3. Vendo 1 unidad (stock baja a 1, CPP se mantiene en 2,250).
        //   4. Compro 2 unidades a L 2,645 c/ISV (= L 2,300 NETO) → CPP = (1×2250 + 2×2300) / 3 = 2,283.33.

        $product = $this->makeProduct(costPrice: 2500, stock: 1);

        // ── Paso 1: Compra de 1 unidad a L 2,300 c/ISV (= 2,000 NETO) ──
        $purchase1 = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 2300],
        ]);
        $this->service->confirm($purchase1);
        $product->refresh();

        $this->assertEquals(2250.00, (float) $product->cost_price,
            'Tras compra: CPP = (1×2500 + 1×2000) / 2 = 2,250');
        $this->assertEquals(2, $product->stock);

        // ── Paso 2: Simular venta de 1 unidad ──
        $product->update(['stock' => 1]);
        $product->refresh();

        $this->assertEquals(2250.00, (float) $product->cost_price,
            'Tras venta: el CPP NO se mueve — las unidades restantes ya tenían ese costo');

        // ── Paso 3: Compra de 2 unidades a L 2,645 c/ISV (= 2,300 NETO) ──
        $purchase2 = $this->makePurchase([
            ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 2645],
        ]);
        $this->service->confirm($purchase2);
        $product->refresh();

        // (1 × 2250 + 2 × 2300) / 3 = 6850 / 3 = 2283.3333... → round(4) = 2283.3333
        // Precisión interna 4 decimales en cost_price (DECIMAL(12,4)): el CPP
        // ya no truncará centavos en cada compra sucesiva. Al mostrar al
        // usuario el accessor / display aplica round(_, 2) al final.
        $this->assertEquals(2283.3333, (float) $product->cost_price,
            'CPP debe usar SOLO el stock disponible (1 unidad a 2,250) — la unidad vendida no participa');
        $this->assertEquals(3, $product->stock);
    }

    // ─── netUnitCost helper: regla de back-out cubierta exhaustivamente ──

    /**
     * Garantiza que el helper estático `PurchaseService::netUnitCost` cubre
     * las cuatro combinaciones posibles de documento × tax_type. Cualquier
     * regresión en la regla de back-out se detecta acá sin necesidad de
     * confirmar una compra completa.
     */
    public function test_net_unit_cost_helper_covers_all_combinations(): void
    {
        // Factura + Gravado15 → back-out (1150 → 1000)
        $this->assertEquals(
            1000.00,
            PurchaseService::netUnitCost(1150, TaxType::Gravado15, SupplierDocumentType::Factura)
        );

        // Factura + Exento → sin back-out (1000 → 1000)
        $this->assertEquals(
            1000.00,
            PurchaseService::netUnitCost(1000, TaxType::Exento, SupplierDocumentType::Factura)
        );

        // RI + Gravado15 → sin back-out (1000 → 1000)
        $this->assertEquals(
            1000.00,
            PurchaseService::netUnitCost(1000, TaxType::Gravado15, SupplierDocumentType::ReciboInterno)
        );

        // RI + Exento → sin back-out (1000 → 1000)
        $this->assertEquals(
            1000.00,
            PurchaseService::netUnitCost(1000, TaxType::Exento, SupplierDocumentType::ReciboInterno)
        );
    }
}

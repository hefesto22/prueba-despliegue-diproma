<?php

namespace Tests\Feature\Services;

use App\Enums\MovementType;
use App\Enums\PaymentStatus;
use App\Enums\ProductCondition;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Enums\TaxType;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Services\Purchases\Exceptions\CompraConProductosHeredadaException;
use App\Services\Purchases\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * PurchaseService tras la Fase 1 del rediseño Compras + Producto-lote:
 * una compra es un documento fiscal sin líneas.
 *
 * Contratos cubiertos:
 *   - Confirmar solo cambia estado/pago: nunca stock, costo ni Kardex.
 *   - Un borrador heredado con líneas no se puede confirmar.
 *   - Anular una compra heredada confirmada revierte su stock con el mismo
 *     costo NETO con que entró (entrada + salida cuadran en el Kardex).
 *   - Anular una compra actual solo cambia su estado.
 *
 * "Compra heredada" = capturada antes de la Fase 1, con `purchase_items`.
 * El helper makeLegacyConfirmedPurchase() reproduce lo que dejaba en BD la
 * confirmación del modelo anterior (línea + entrada al Kardex + stock).
 */
class PurchaseServiceTest extends TestCase
{
    use CreatesMatriz, RefreshDatabase;

    private PurchaseService $service;

    private Supplier $supplier;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PurchaseService::class);
        $this->supplier = Supplier::factory()->create();
        $this->category = Category::factory()->create();
    }

    private function makeProduct(float $costPrice = 1000, int $stock = 5): Product
    {
        return Product::factory()->inCategory($this->category)->create([
            'cost_price' => $costPrice,
            'stock' => $stock,
            'condition' => ProductCondition::New,
            'tax_type' => TaxType::Gravado15,
        ]);
    }

    /**
     * Compra del modelo actual: documento con montos, sin líneas.
     */
    private function makeDocumentPurchase(array $attributes = []): Purchase
    {
        return Purchase::factory()
            ->fromSupplier($this->supplier)
            ->withTotals(taxable: 1000, exempt: 200)
            ->create([
                'date' => now(),
                'status' => PurchaseStatus::Borrador,
                ...$attributes,
            ]);
    }

    /**
     * Estado que dejaba en BD una compra confirmada con el modelo anterior:
     * la línea, su entrada al Kardex con costo NETO y el stock incrementado.
     */
    private function makeLegacyConfirmedPurchase(Product $product, int $quantity, float $unitCostWithIsv): Purchase
    {
        $purchase = Purchase::factory()
            ->fromSupplier($this->supplier)
            ->confirmada()
            ->create([
                'date' => now(),
                'payment_status' => PaymentStatus::Pagada,
            ]);

        PurchaseItem::factory()->forPurchase($purchase)->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCostWithIsv,
            'tax_type' => TaxType::Gravado15,
        ]);

        InventoryMovement::record(
            product: $product,
            type: MovementType::EntradaCompra,
            quantity: $quantity,
            reference: $purchase,
            unitCost: PurchaseService::netUnitCost($unitCostWithIsv, TaxType::Gravado15, SupplierDocumentType::Factura),
            establishment: $purchase->establishment,
        );
        $product->update(['stock' => $product->stock + $quantity]);

        return $purchase;
    }

    // ─── Confirmar ───────────────────────────────────────────

    public function test_confirmar_marca_confirmada_sin_tocar_stock_costo_ni_kardex(): void
    {
        $product = $this->makeProduct(costPrice: 1000, stock: 5);
        $purchase = $this->makeDocumentPurchase();

        $this->service->confirm($purchase);

        $this->assertSame(PurchaseStatus::Confirmada, $purchase->status,
            'confirm() debe refrescar la instancia del caller con el estado nuevo.');

        $product->refresh();
        $this->assertSame(5, $product->stock);
        $this->assertEquals(1000.0, (float) $product->cost_price);
        $this->assertSame(0, InventoryMovement::count(),
            'Una compra-documento no registra movimientos de inventario.');
    }

    public function test_confirmar_no_altera_los_montos_del_documento(): void
    {
        $purchase = $this->makeDocumentPurchase();

        $this->service->confirm($purchase);

        // Antes, confirm() recalculaba desde líneas: sin líneas, esto dejaría
        // todo en cero. Los montos transcritos del documento mandan.
        $this->assertEquals(1000.00, (float) $purchase->taxable_total);
        $this->assertEquals(200.00, (float) $purchase->exempt_total);
        $this->assertEquals(150.00, (float) $purchase->isv);
        $this->assertEquals(1350.00, (float) $purchase->total);
    }

    public function test_confirmar_compra_al_contado_la_marca_pagada(): void
    {
        $purchase = $this->makeDocumentPurchase(['credit_days' => 0]);
        $this->assertSame(PaymentStatus::Pendiente, $purchase->payment_status);

        $this->service->confirm($purchase);

        $this->assertSame(PaymentStatus::Pagada, $purchase->payment_status);
    }

    public function test_confirmar_compra_a_credito_mantiene_pago_pendiente(): void
    {
        $purchase = $this->makeDocumentPurchase(['credit_days' => 30]);

        $this->service->confirm($purchase);

        $this->assertSame(PurchaseStatus::Confirmada, $purchase->status);
        $this->assertSame(PaymentStatus::Pendiente, $purchase->payment_status,
            'A crédito el pago queda Pendiente hasta que exista Cuentas por Pagar.');
    }

    public function test_confirmar_falla_si_no_es_borrador(): void
    {
        $purchase = $this->makeDocumentPurchase(['status' => PurchaseStatus::Confirmada]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->confirm($purchase);
    }

    public function test_confirmar_valida_el_estado_de_la_bd_no_el_de_la_instancia(): void
    {
        // Instancia rancia: otra pestaña ya confirmó la compra.
        $purchase = $this->makeDocumentPurchase();
        Purchase::query()->whereKey($purchase->id)->update(['status' => PurchaseStatus::Confirmada]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->confirm($purchase);
    }

    public function test_borrador_heredado_con_productos_no_se_puede_confirmar(): void
    {
        $product = $this->makeProduct(stock: 5);
        $purchase = $this->makeDocumentPurchase();
        PurchaseItem::factory()->forPurchase($purchase)->create([
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_cost' => 1150,
        ]);

        try {
            $this->service->confirm($purchase);
            $this->fail('Debió rechazar el borrador heredado con productos.');
        } catch (CompraConProductosHeredadaException $e) {
            $this->assertStringContainsString($purchase->purchase_number, $e->getMessage());
        }

        $this->assertSame(PurchaseStatus::Borrador, $purchase->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_borrador_recien_creado_queda_pendiente_de_pago(): void
    {
        $purchase = $this->makeDocumentPurchase();

        $this->assertSame(PaymentStatus::Pendiente, $purchase->payment_status,
            'El pago se resuelve al confirmar, no al crear el borrador.');
    }

    // ─── Anular: compras del modelo actual ───────────────────

    public function test_anular_borrador_no_tiene_efectos(): void
    {
        $product = $this->makeProduct(stock: 5);
        $purchase = $this->makeDocumentPurchase();

        $this->service->cancel($purchase);

        $this->assertSame(PurchaseStatus::Anulada, $purchase->status);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_anular_compra_confirmada_sin_lineas_solo_cambia_el_estado(): void
    {
        $product = $this->makeProduct(stock: 5);
        $purchase = $this->makeDocumentPurchase();
        $this->service->confirm($purchase);

        $this->service->cancel($purchase);

        $this->assertSame(PurchaseStatus::Anulada, $purchase->status);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_anular_no_modifica_el_payment_status_historico(): void
    {
        $purchase = $this->makeDocumentPurchase();
        $this->service->confirm($purchase);
        $this->assertSame(PaymentStatus::Pagada, $purchase->payment_status);

        $this->service->cancel($purchase);

        $this->assertSame(PaymentStatus::Pagada, $purchase->payment_status,
            'Si era contado el dinero ya se entregó: anular no lo "des-paga".');
    }

    public function test_anular_dos_veces_falla(): void
    {
        $purchase = $this->makeDocumentPurchase();
        $this->service->cancel($purchase);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->cancel($purchase);
    }

    // ─── Anular: compras heredadas con productos ─────────────

    public function test_anular_compra_heredada_confirmada_revierte_su_stock(): void
    {
        $product = $this->makeProduct(stock: 5);
        $purchase = $this->makeLegacyConfirmedPurchase($product, quantity: 3, unitCostWithIsv: 1380);
        $this->assertSame(8, $product->fresh()->stock);

        $this->service->cancel($purchase);

        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(PurchaseStatus::Anulada, $purchase->status);
    }

    public function test_reversa_de_compra_heredada_usa_el_mismo_costo_neto_que_la_entrada(): void
    {
        $product = $this->makeProduct(stock: 5);
        $purchase = $this->makeLegacyConfirmedPurchase($product, quantity: 3, unitCostWithIsv: 1380); // 1200 NETO

        $this->service->cancel($purchase);

        $movements = InventoryMovement::query()
            ->where('reference_type', Purchase::class)
            ->where('reference_id', $purchase->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $movements);
        $this->assertSame(MovementType::EntradaCompra, $movements[0]->type);
        $this->assertSame(MovementType::SalidaAnulacionCompra, $movements[1]->type);
        $this->assertEquals(1200.00, (float) $movements[1]->unit_cost,
            'Entrada y salida deben cuadrar a cero en el Kardex.');
        $this->assertSame(8, $movements[1]->stock_before);
        $this->assertSame(5, $movements[1]->stock_after);
    }

    public function test_reversa_de_compra_heredada_nunca_deja_stock_negativo(): void
    {
        $product = $this->makeProduct(stock: 0);
        $purchase = $this->makeLegacyConfirmedPurchase($product, quantity: 5, unitCostWithIsv: 1150);

        // Se vendieron todas las unidades antes de anular.
        $product->update(['stock' => 0]);

        $this->service->cancel($purchase);

        $this->assertSame(0, $product->fresh()->stock);
    }

    // ─── netUnitCost: regla de back-out ──────────────────────

    public function test_net_unit_cost_cubre_las_cuatro_combinaciones(): void
    {
        $this->assertEquals(1000.00, PurchaseService::netUnitCost(1150, TaxType::Gravado15, SupplierDocumentType::Factura));
        $this->assertEquals(1000.00, PurchaseService::netUnitCost(1000, TaxType::Exento, SupplierDocumentType::Factura));
        $this->assertEquals(1000.00, PurchaseService::netUnitCost(1000, TaxType::Gravado15, SupplierDocumentType::ReciboInterno));
        $this->assertEquals(1000.00, PurchaseService::netUnitCost(1000, TaxType::Exento, SupplierDocumentType::ReciboInterno));
    }
}

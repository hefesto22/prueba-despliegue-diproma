<?php

namespace Tests\Feature\Services;

use App\Enums\CreditNoteReason;
use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\TaxType;
use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\Establishment;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\CreditNotes\CreditNoteService;
use App\Services\CreditNotes\DTOs\EmitirNotaCreditoInput;
use App\Services\CreditNotes\DTOs\LineaAcreditarInput;
use App\Services\CreditNotes\Exceptions\CantidadYaAcreditadaException;
use App\Services\CreditNotes\Exceptions\FacturaAnuladaNoAcreditableException;
use App\Services\CreditNotes\Exceptions\FacturaWithoutCaiNoAcreditableException;
use App\Services\CreditNotes\Exceptions\NotaCreditoYaAnuladaException;
use App\Services\CreditNotes\Exceptions\StockInsuficienteParaAnularNCException;
use App\Services\Invoicing\InvoiceService;
use App\Services\Sales\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Contrato del servicio de Notas de Crédito:
 *  - Snapshot fiscal inmutable al emitir (emisor, receptor, factura origen, totales).
 *  - Bloqueo de facturas inelegibles (anuladas, sin CAI).
 *  - Validación acumulativa que considera NCs previas no anuladas.
 *  - Reversión de kardex solo en devolucion_fisica, usando unit_cost del SalidaVenta original.
 *  - Aplicación de ratio de descuento proporcional de la factura origen.
 *  - Sellado con emitted_at + integrity_hash al final de la transacción.
 */
class CreditNoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private CreditNoteService $service;
    private InvoiceService $invoiceService;
    private SaleService $saleService;
    private CompanySetting $company;
    private Establishment $matriz;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('company_settings');
        config(['invoicing.mode' => 'centralizado']);

        $this->company = CompanySetting::factory()->create([
            'legal_name' => 'Diproma S. de R.L.',
            'trade_name' => 'Diproma',
            'rtn'        => '08011999000001',
            'address'    => 'Barrio Guamilito, 5ta Ave',
            'phone'      => '2550-0000',
            'email'      => 'diproma@test.com',
        ]);
        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        // C2 — toda venta nace dentro de una sesión de caja abierta.
        // Opening en setUp para que las ventas que emite este test (vía
        // SaleService::processSale en sellAndInvoice) no fallen con
        // NoHayCajaAbiertaException. Las NCs en sí no tocan caja directamente;
        // solo su factura origen lo hace en este flujo.
        $cajero = User::factory()->create();
        $this->actingAs($cajero);
        app(CashSessionService::class)->open(
            establishmentId: $this->matriz->id,
            openedBy: $cajero,
            openingAmount: 1000.00,
        );

        // CAI para facturas
        CaiRange::factory()->active()->create([
            'prefix'          => '001-001-01',
            'document_type'   => '01',
            'range_start'     => 1,
            'range_end'       => 100,
            'current_number'  => 0,
        ]);

        // CAI para notas de crédito
        CaiRange::factory()->active()->create([
            'prefix'          => '001-001-03',
            'document_type'   => '03',
            'range_start'     => 1,
            'range_end'       => 100,
            'current_number'  => 0,
        ]);

        $this->invoiceService = app(InvoiceService::class);
        $this->saleService    = app(SaleService::class);
        $this->service        = app(CreditNoteService::class);
    }

    // ─── Helpers ──────────────────────────────────────────────

    /**
     * Procesa una venta real vía SaleService (crea SalidaVenta en kardex),
     * emite la factura, y retorna [Invoice, Product].
     *
     * @param  int       $stock      Stock inicial del producto.
     * @param  float     $unitPrice  Precio unitario con ISV.
     * @param  int       $quantity   Cantidad a vender.
     * @param  TaxType   $taxType
     * @param  float     $costPrice  Costo para el kardex.
     */
    private function sellAndInvoice(
        int $stock = 10,
        float $unitPrice = 115.00,
        int $quantity = 2,
        TaxType $taxType = TaxType::Gravado15,
        float $costPrice = 50.00,
    ): array {
        $product = Product::factory()->create([
            'stock'      => $stock,
            'cost_price' => $costPrice,
            'tax_type'   => $taxType,
        ]);

        $sale = $this->saleService->processSale(
            cartItems: [[
                'product_id' => $product->id,
                'quantity'   => $quantity,
                'unit_price' => $unitPrice,
                'tax_type'   => $taxType->value,
            ]],
            paymentMethod: PaymentMethod::Efectivo,
            customerName: 'Cliente Test',
            customerRtn:  '08011999000999',
        );

        $invoice = $this->invoiceService->generateFromSale($sale->fresh(['items']));

        return [$invoice->fresh(['sale.items']), $product->fresh()];
    }

    /**
     * Variante multi-item: vende N líneas en una sola venta+factura.
     * Devuelve [Invoice, Product[]] — el orden de Product[] coincide con $lines.
     *
     * Usado exclusivamente por los tests de paridad pre-refactor E.2.A3 que
     * necesitan escenarios mixtos gravado/exento en una misma factura.
     *
     * @param  array<int, array{unit_price: float, quantity: int, tax_type: TaxType, stock?: int, cost_price?: float}>  $lines
     * @return array{0: Invoice, 1: array<int, Product>}
     */
    private function sellAndInvoiceMulti(array $lines): array
    {
        $products  = [];
        $cartItems = [];

        foreach ($lines as $line) {
            $product = Product::factory()->create([
                'stock'      => $line['stock']      ?? 100,
                'cost_price' => $line['cost_price'] ?? 50.00,
                'tax_type'   => $line['tax_type'],
            ]);
            $products[]  = $product;
            $cartItems[] = [
                'product_id' => $product->id,
                'quantity'   => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_type'   => $line['tax_type']->value,
            ];
        }

        $sale = $this->saleService->processSale(
            cartItems:     $cartItems,
            paymentMethod: PaymentMethod::Efectivo,
            customerName:  'Cliente Test',
            customerRtn:   '08011999000999',
        );

        $invoice = $this->invoiceService->generateFromSale($sale->fresh(['items']));

        return [$invoice->fresh(['sale.items']), $products];
    }

    // ─── Happy path ──────────────────────────────────────────

    public function test_emite_nc_con_snapshot_fiscal_completo(): void
    {
        [$invoice] = $this->sellAndInvoice(quantity: 2, unitPrice: 115.00);
        $saleItem = $invoice->sale->items->first();

        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 1)],
        ));

        // Número SAR con prefijo '03'
        $this->assertEquals('001-001-03-00000001', $nc->credit_note_number);
        $this->assertNotNull($nc->cai);
        $this->assertFalse($nc->without_cai);

        // Snapshot emisor desde CompanySetting
        $this->assertEquals('Diproma', $nc->company_name);
        $this->assertEquals('08011999000001', $nc->company_rtn);

        // Snapshot receptor desde la factura
        $this->assertEquals($invoice->customer_name, $nc->customer_name);
        $this->assertEquals($invoice->customer_rtn, $nc->customer_rtn);

        // Snapshot factura origen
        $this->assertEquals($invoice->invoice_number, $nc->original_invoice_number);
        $this->assertEquals($invoice->cai, $nc->original_invoice_cai);

        // Totales: 1 x 115 con ISV => base 100, isv 15
        $this->assertEquals(100.00, (float) $nc->taxable_total);
        $this->assertEquals(15.00,  (float) $nc->isv);
        $this->assertEquals(115.00, (float) $nc->total);

        // Items
        $this->assertCount(1, $nc->items);
        $this->assertEquals(1, $nc->items->first()->quantity);
    }

    public function test_avanza_correlativo_del_cai_03_al_emitir(): void
    {
        [$invoice] = $this->sellAndInvoice();
        $saleItem = $invoice->sale->items->first();

        $nc1 = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 1)],
        ));

        // Segunda factura + NC para ver que el correlativo avanza
        [$invoice2] = $this->sellAndInvoice();
        $saleItem2 = $invoice2->sale->items->first();

        $nc2 = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice2,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem2->id, 1)],
        ));

        $this->assertEquals('001-001-03-00000001', $nc1->credit_note_number);
        $this->assertEquals('001-001-03-00000002', $nc2->credit_note_number);
    }

    public function test_sella_emitted_at_e_integrity_hash(): void
    {
        [$invoice] = $this->sellAndInvoice();
        $saleItem = $invoice->sale->items->first();

        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 1)],
        ));

        $this->assertNotNull($nc->emitted_at);
        $this->assertNotNull($nc->integrity_hash);
        $this->assertEquals(64, strlen($nc->integrity_hash)); // sha256 hex
    }

    // ─── Bloqueos fiscales ────────────────────────────────────

    public function test_bloquea_nc_sobre_factura_anulada(): void
    {
        [$invoice] = $this->sellAndInvoice();
        $saleItem  = $invoice->sale->items->first();
        $invoice->void();

        $this->expectException(FacturaAnuladaNoAcreditableException::class);

        $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice->fresh(),
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 1)],
        ));
    }

    public function test_bloquea_nc_sobre_factura_sin_cai(): void
    {
        // Emitir factura SIN CAI (referencia interna)
        $product = Product::factory()->create(['stock' => 10, 'cost_price' => 50]);
        $sale    = $this->saleService->processSale(
            cartItems: [[
                'product_id' => $product->id,
                'quantity'   => 2,
                'unit_price' => 115.00,
                'tax_type'   => TaxType::Gravado15->value,
            ]],
            paymentMethod: PaymentMethod::Efectivo,
            customerName: 'Cliente Test',
        );
        $invoice = $this->invoiceService->generateFromSale($sale->fresh(['items']), withoutCai: true);
        $saleItem = $invoice->sale->items->first();

        $this->expectException(FacturaWithoutCaiNoAcreditableException::class);

        $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 1)],
        ));
    }

    public function test_rechaza_sale_item_que_no_pertenece_a_la_factura(): void
    {
        [$invoiceA] = $this->sellAndInvoice();
        [$invoiceB] = $this->sellAndInvoice();
        $saleItemDeB = $invoiceB->sale->items->first();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoiceA,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItemDeB->id, 1)],
        ));
    }

    // ─── Validación acumulativa ───────────────────────────────

    public function test_validacion_acumulativa_bloquea_exceder_cantidad_vendida(): void
    {
        [$invoice] = $this->sellAndInvoice(quantity: 2);
        $saleItem  = $invoice->sale->items->first();

        $this->expectException(CantidadYaAcreditadaException::class);

        $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 3)], // > vendido
        ));
    }

    public function test_suma_ncs_previas_no_anuladas_en_validacion_acumulativa(): void
    {
        [$invoice] = $this->sellAndInvoice(quantity: 3);
        $saleItem  = $invoice->sale->items->first();

        // Primera NC: 2 unidades (OK, quedan 1)
        $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 2)],
        ));

        // Segunda NC: pedir 2 más → debe fallar (solo queda 1)
        $this->expectException(CantidadYaAcreditadaException::class);

        $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 2)],
        ));
    }

    public function test_nc_anulada_no_cuenta_contra_el_saldo(): void
    {
        [$invoice] = $this->sellAndInvoice(quantity: 2);
        $saleItem  = $invoice->sale->items->first();

        $nc1 = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 2)],
        ));

        // Anular la primera NC: su cantidad deja de contar contra el saldo
        $this->service->voidNotaCredito($nc1);

        // Ahora puedo emitir una nueva NC por las 2 unidades completas
        $nc2 = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice->fresh(),
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 2)],
        ));

        $this->assertNotEquals($nc1->id, $nc2->id);
        $this->assertEquals(2, $nc2->items->sum('quantity'));
    }

    // ─── Kardex ───────────────────────────────────────────────

    public function test_devolucion_fisica_registra_entrada_nota_credito_y_suma_stock(): void
    {
        [$invoice, $product] = $this->sellAndInvoice(
            stock:     10,
            unitPrice: 115.00,
            quantity:  2,
            costPrice: 50.00,
        );
        // Stock post-venta: 10 - 2 = 8
        $this->assertEquals(8, $product->fresh()->stock);

        $saleItem = $invoice->sale->items->first();

        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 1)],
        ));

        // Stock vuelve: 8 + 1 = 9
        $this->assertEquals(9, $product->fresh()->stock);

        // Existe movimiento EntradaNotaCredito referenciando a la NC
        $movement = InventoryMovement::where('reference_type', CreditNote::class)
            ->where('reference_id', $nc->id)
            ->firstOrFail();

        $this->assertEquals(MovementType::EntradaNotaCredito, $movement->type);
        $this->assertEquals(1, $movement->quantity);
    }

    public function test_devolucion_fisica_usa_unit_cost_original_del_movimiento_salida(): void
    {
        [$invoice, $product] = $this->sellAndInvoice(costPrice: 50.00);
        $saleItem = $invoice->sale->items->first();

        // El movimiento SalidaVenta tiene unit_cost=50
        $salida = InventoryMovement::where('product_id', $product->id)
            ->where('type', MovementType::SalidaVenta)
            ->firstOrFail();
        $this->assertEquals(50.00, (float) $salida->unit_cost);

        // Cambiamos el cost_price del producto DESPUÉS de la venta
        $product->update(['cost_price' => 99.99]);

        // Emitimos NC: el movimiento de entrada debe preservar 50, no 99.99
        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 1)],
        ));

        $entrada = InventoryMovement::where('reference_type', CreditNote::class)
            ->where('reference_id', $nc->id)
            ->firstOrFail();

        $this->assertEquals(50.00, (float) $entrada->unit_cost);
    }

    public function test_razones_no_fisicas_no_mueven_kardex(): void
    {
        [$invoice, $product] = $this->sellAndInvoice(quantity: 2);
        $stockAntes = $product->fresh()->stock;

        $saleItem = $invoice->sale->items->first();

        $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice:     $invoice,
            reason:      CreditNoteReason::CorreccionError,
            lineas:      [new LineaAcreditarInput($saleItem->id, 1)],
            reasonNotes: 'Error en digitación del precio',
        ));

        // Stock NO debe cambiar
        $this->assertEquals($stockAntes, $product->fresh()->stock);

        // No debe haber movimiento EntradaNotaCredito
        $this->assertDatabaseMissing('inventory_movements', [
            'product_id' => $product->id,
            'type'       => MovementType::EntradaNotaCredito->value,
        ]);
    }

    // ─── Cálculo fiscal con descuento ─────────────────────────

    public function test_aplica_ratio_de_descuento_proporcional_de_la_factura_origen(): void
    {
        // Simulamos una factura con descuento manipulando el subtotal/isv/discount.
        // Para este test manipulamos los campos fiscales manualmente en una
        // factura "no sellada" via UPDATE directo en DB (bypass del trait)
        // porque recrear flujo con descuento requeriría una venta con descuento.
        //
        // ⚠️ Valores alineados con el fix E.2.A3: el ratio se deriva del
        // `invoice.total + invoice.discount` (gross real), NO de
        // `taxable_total + exempt_total + isv + discount` (fórmula previa
        // que double-counteaba el exempt en facturas mix). Detalle completo
        // en el PHPDoc del bloque "Paridad del cálculo".
        [$invoice] = $this->sellAndInvoice(unitPrice: 115.00, quantity: 2);

        // Factura bruta: taxable=200, isv=30, total=230
        // Inyectamos descuento=23 → gross correcto = 207 + 23 = 230, ratio = 23/230 = 0.10
        Invoice::where('id', $invoice->id)->update([
            'discount' => 23.00,
            'total'    => 207.00,
        ]);
        $invoice = $invoice->fresh(['sale.items']);

        $saleItem = $invoice->sale->items->first();

        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 1)],
        ));

        // Nominal de la línea (qty=1 de gravado 115): base=100, isv=15, total=115
        // Post-fix: ratio = 23/230 = 0.10 exacto
        //   taxable' = round(100 * 0.90, 2) = 90.00
        //   isv'     = round( 15 * 0.90, 2) = 13.50
        //   total    = round(90.00 + 13.50, 2) = 103.50
        $this->assertSame(90.00,  (float) $nc->taxable_total);
        $this->assertSame(13.50,  (float) $nc->isv);
        $this->assertSame(103.50, (float) $nc->total);
    }

    public function test_factura_sin_descuento_acredita_precio_nominal_exacto(): void
    {
        [$invoice] = $this->sellAndInvoice(unitPrice: 115.00, quantity: 2);
        $saleItem  = $invoice->sale->items->first();

        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 1)],
        ));

        $this->assertEquals(100.00, (float) $nc->taxable_total);
        $this->assertEquals(15.00, (float) $nc->isv);
        $this->assertEquals(115.00, (float) $nc->total);
    }

    // ─── Cálculo fiscal — contratos pre/post refactor E.2.A3 ─
    //
    // Tests #1 y #3 (sin descuento): paridad exacta — comportamiento idéntico
    // pre y post refactor (las rutas sin descuento no tocan el bug detectado).
    //
    // Tests #2 y #4 (con descuento): valores POST-FIX. Capturan el resultado
    // fiscalmente correcto, no el actual.
    //
    // Bug detectado durante la escritura de paridad: la fórmula vigente
    // computa `gross = taxable_total + exempt_total + isv + discount`, pero
    // `taxable_total` (= `Sale.subtotal` = `SaleTaxCalculator.subtotal`)
    // ya incluye TANTO la base de gravado COMO la base de exento. Sumar
    // `exempt_total` aparte double-countea la porción exenta y produce un
    // ratio menor al correcto. El fix usa `gross = invoice.total + invoice.discount`,
    // que no admite ambigüedad.
    //
    // Pre-refactor: tests #2 y #4 fallan (impl actual produce valores buggy).
    // Post-refactor: todos pasan; el `CreditNoteTotalsCalculator` corrige el
    // gross por construcción al delegar el desglose por línea a
    // `SaleTaxCalculator` y derivar el ratio desde `invoice.total + invoice.discount`.
    //
    // Nota: el bug es dormant en facturas all-gravado o all-exento (porque
    // `exempt_total = 0` o `taxable_total = exempt_total`). Solo se activa
    // en mix gravado/exento + descuento — escenario poco común pero
    // fiscalmente relevante (Libro de Ventas SAR, Form 210 ISV).

    public function test_paridad_gravado_puro_sin_descuento_redondeo_no_trivial(): void
    {
        // 46.55 * 3 = 139.65 → base = 139.65/1.15 = 121.43478... → round 121.43
        // isv = 139.65 - 121.43 = 18.22 (exacto)
        [$invoice] = $this->sellAndInvoice(
            stock:     10,
            unitPrice: 46.55,
            quantity:  3,
            taxType:   TaxType::Gravado15,
        );
        $saleItem = $invoice->sale->items->first();

        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 3)],
        ));

        // Exactos — sin delta. Capturan la regla de redondeo per-línea actual.
        $this->assertSame(121.43, (float) $nc->taxable_total);
        $this->assertSame(0.00,   (float) $nc->exempt_total);
        $this->assertSame(18.22,  (float) $nc->isv);
        $this->assertSame(139.65, (float) $nc->total);
    }

    public function test_paridad_gravado_con_descuento_valores_exactos(): void
    {
        // Factura nominal: base=200, isv=30, total=230.
        // Inyectamos descuento=25 → gross correcto = invoice.total + invoice.discount = 205 + 25 = 230.
        // ratio = 25/230 = 0.10869565217391...
        // (1 - ratio) = 0.89130434782608...
        // Acreditamos la línea completa (qty=2 → base 200, isv 30):
        //   taxable' = round(200 * 0.89130..., 2) = 178.26
        //   isv'     = round(30  * 0.89130..., 2) =  26.74
        //   total    = round(178.26 + 26.74, 2)   = 205.00
        [$invoice] = $this->sellAndInvoice(
            stock:     10,
            unitPrice: 115.00,
            quantity:  2,
            taxType:   TaxType::Gravado15,
        );

        Invoice::where('id', $invoice->id)->update([
            'discount' => 25.00,
            'total'    => 205.00, // 230 - 25 (los campos base/isv siguen 200/30)
        ]);
        $invoice = $invoice->fresh(['sale.items']);

        $saleItem = $invoice->sale->items->first();

        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 2)],
        ));

        $this->assertSame(178.26, (float) $nc->taxable_total);
        $this->assertSame(0.00,   (float) $nc->exempt_total);
        $this->assertSame(26.74,  (float) $nc->isv);
        $this->assertSame(205.00, (float) $nc->total);
    }

    public function test_paridad_mix_gravado_y_exento_sin_descuento(): void
    {
        // Línea A (gravado): 115 * 2 = 230 → base 200, isv 30.
        // Línea B (exento):   50 * 3 = 150 → exempt 150.
        // Acreditamos ambas líneas completas, sin descuento.
        [$invoice, $products] = $this->sellAndInvoiceMulti([
            ['unit_price' => 115.00, 'quantity' => 2, 'tax_type' => TaxType::Gravado15, 'stock' => 10],
            ['unit_price' =>  50.00, 'quantity' => 3, 'tax_type' => TaxType::Exento,    'stock' => 10],
        ]);

        // Los saleItems se crean en el mismo orden que el cartItems pasado a processSale.
        $saleItems = $invoice->sale->items
            ->sortBy('id')
            ->values();
        $saleItemA = $saleItems[0]; // gravado
        $saleItemB = $saleItems[1]; // exento

        // Resguardo del supuesto del test — si el orden de inserción cambia, el test
        // falla con un mensaje claro en vez de producir aserciones engañosas.
        $this->assertEquals($products[0]->id, $saleItemA->product_id, 'SaleItem A debe corresponder al producto gravado');
        $this->assertEquals($products[1]->id, $saleItemB->product_id, 'SaleItem B debe corresponder al producto exento');

        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [
                new LineaAcreditarInput($saleItemA->id, 2),
                new LineaAcreditarInput($saleItemB->id, 3),
            ],
        ));

        $this->assertSame(200.00, (float) $nc->taxable_total);
        $this->assertSame(150.00, (float) $nc->exempt_total);
        $this->assertSame(30.00,  (float) $nc->isv);
        $this->assertSame(380.00, (float) $nc->total);
    }

    public function test_paridad_mix_gravado_y_exento_con_descuento(): void
    {
        // Factura nominal: gravado base=200, exempt=150, isv=30, total=380.
        // Inyectamos descuento=40 → gross correcto = invoice.total + invoice.discount
        //                        = 340 + 40 = 380
        // (El fix E.2.A3 NO lee `taxable_total + exempt_total + isv + discount` porque
        //  eso double-counteaba el exempt — `taxable_total` ya contiene la base de
        //  gravado Y exempt por convención de `SaleTaxCalculator.subtotal`.)
        // ratio        = 40/380 = 0.10526315789473...
        // (1 - ratio)  = 0.89473684210526...
        //   taxable' = round(200 * 0.89473..., 2) = 178.95
        //   exempt'  = round(150 * 0.89473..., 2) = 134.21
        //   isv'     = round( 30 * 0.89473..., 2) =  26.84
        //   total    = round(178.95 + 134.21 + 26.84, 2) = 340.00
        [$invoice, $products] = $this->sellAndInvoiceMulti([
            ['unit_price' => 115.00, 'quantity' => 2, 'tax_type' => TaxType::Gravado15, 'stock' => 10],
            ['unit_price' =>  50.00, 'quantity' => 3, 'tax_type' => TaxType::Exento,    'stock' => 10],
        ]);

        Invoice::where('id', $invoice->id)->update([
            'discount' => 40.00,
            'total'    => 340.00, // 380 - 40
        ]);
        $invoice = $invoice->fresh(['sale.items']);

        $saleItems = $invoice->sale->items->sortBy('id')->values();
        $saleItemA = $saleItems[0];
        $saleItemB = $saleItems[1];

        $this->assertEquals($products[0]->id, $saleItemA->product_id);
        $this->assertEquals($products[1]->id, $saleItemB->product_id);

        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [
                new LineaAcreditarInput($saleItemA->id, 2),
                new LineaAcreditarInput($saleItemB->id, 3),
            ],
        ));

        $this->assertSame(178.95, (float) $nc->taxable_total);
        $this->assertSame(134.21, (float) $nc->exempt_total);
        $this->assertSame(26.84,  (float) $nc->isv);
        $this->assertSame(340.00, (float) $nc->total);
    }

    // ─── DTOs ─────────────────────────────────────────────────

    public function test_dto_rechaza_lineas_duplicadas(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duplicado/i');

        new EmitirNotaCreditoInput(
            invoice: Invoice::factory()->make(['id' => 1]),
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [
                new LineaAcreditarInput(10, 1),
                new LineaAcreditarInput(10, 2),
            ],
        );
    }

    public function test_dto_rechaza_razon_que_requiere_notas_sin_notas(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/notas explicativas/i');

        new EmitirNotaCreditoInput(
            invoice: Invoice::factory()->make(['id' => 1]),
            reason:  CreditNoteReason::CorreccionError, // requires notes
            lineas:  [new LineaAcreditarInput(10, 1)],
            reasonNotes: '   ', // whitespace → inválido
        );
    }

    public function test_dto_acepta_devolucion_fisica_sin_notas(): void
    {
        $dto = new EmitirNotaCreditoInput(
            invoice: Invoice::factory()->make(['id' => 1]),
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput(10, 1)],
        );

        $this->assertEquals(CreditNoteReason::DevolucionFisica, $dto->reason);
        $this->assertNull($dto->reasonNotes);
    }

    public function test_linea_rechaza_cantidad_no_positiva(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LineaAcreditarInput(saleItemId: 1, quantity: 0);
    }

    // ─── voidNotaCredito ──────────────────────────────────────
    //
    // Contrato de anulacion de NC:
    //  - Transaccional con lockForUpdate sobre la NC.
    //  - Si razon->returnsToInventory(): revierte kardex registrando
    //    SalidaAnulacionNotaCredito con unit_cost del EntradaNotaCredito original.
    //  - Si razon no toca inventario: solo marca is_void, no hay kardex.
    //  - Fail fast en doble anulacion (NotaCreditoYaAnuladaException).
    //  - Fail fast si stock actual no alcanza para revertir la entrada
    //    (mercaderia ya revendida): StockInsuficienteParaAnularNCException.
    //  - El hash NO se recalcula: QR impreso sigue verificable.

    public function test_anular_nc_con_devolucion_fisica_revierte_stock_y_registra_salida(): void
    {
        [$invoice, $product] = $this->sellAndInvoice(
            stock:     10,
            unitPrice: 115.00,
            quantity:  2,
            costPrice: 50.00,
        );
        // Stock post-venta: 10 - 2 = 8
        $this->assertEquals(8, $product->fresh()->stock);

        $saleItem = $invoice->sale->items->first();

        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 1)],
        ));
        // Stock post-NC: 8 + 1 = 9 (la NC devolvio mercaderia)
        $this->assertEquals(9, $product->fresh()->stock);

        // Anular la NC: el stock debe volver a 8 (retira lo que devolvio).
        $this->service->voidNotaCredito($nc);

        $this->assertEquals(8, $product->fresh()->stock);
        $this->assertTrue($nc->fresh()->is_void);

        // Debe existir un movimiento SalidaAnulacionNotaCredito que apunta a la NC.
        $salidaAnulacion = InventoryMovement::where('reference_type', CreditNote::class)
            ->where('reference_id', $nc->id)
            ->where('type', MovementType::SalidaAnulacionNotaCredito)
            ->firstOrFail();

        $this->assertEquals(1, $salidaAnulacion->quantity);
        // Costo preservado del EntradaNotaCredito original (50) — no el cost_price actual.
        $this->assertEquals(50.00, (float) $salidaAnulacion->unit_cost);
    }

    public function test_anular_nc_con_razon_no_fisica_no_toca_kardex(): void
    {
        [$invoice, $product] = $this->sellAndInvoice(quantity: 2);
        $stockAntes = $product->fresh()->stock;

        $saleItem = $invoice->sale->items->first();

        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice:     $invoice,
            reason:      CreditNoteReason::CorreccionError,
            lineas:      [new LineaAcreditarInput($saleItem->id, 1)],
            reasonNotes: 'Error en digitación del precio',
        ));

        // Correccion de error: no toco inventario al emitir, no debe tocarlo al anular.
        $this->service->voidNotaCredito($nc);

        $this->assertTrue($nc->fresh()->is_void);
        $this->assertEquals($stockAntes, $product->fresh()->stock);

        // No debe registrar SalidaAnulacionNotaCredito si no hubo EntradaNotaCredito previa.
        $this->assertDatabaseMissing('inventory_movements', [
            'reference_type' => CreditNote::class,
            'reference_id'   => $nc->id,
            'type'           => MovementType::SalidaAnulacionNotaCredito->value,
        ]);
    }

    public function test_anular_nc_dos_veces_lanza_nota_credito_ya_anulada_exception(): void
    {
        [$invoice] = $this->sellAndInvoice(quantity: 1);
        $saleItem  = $invoice->sale->items->first();

        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 1)],
        ));

        $this->service->voidNotaCredito($nc);

        $this->expectException(NotaCreditoYaAnuladaException::class);
        $this->service->voidNotaCredito($nc->fresh());
    }

    public function test_anular_nc_con_stock_insuficiente_lanza_excepcion_y_no_modifica_estado(): void
    {
        // Escenario: cliente devolvio 3 unidades, negocio revendio las 3 a otro
        // cliente, ahora stock = 0. El primer cliente retracta la devolucion y
        // queremos anular la NC — pero no hay 3 unidades en stock para retirar.
        [$invoice, $product] = $this->sellAndInvoice(
            stock:     3,
            unitPrice: 115.00,
            quantity:  3,
            costPrice: 50.00,
        );
        // Stock post-venta: 0
        $this->assertEquals(0, $product->fresh()->stock);

        $saleItem = $invoice->sale->items->first();

        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 3)],
        ));
        // Stock post-NC: 0 + 3 = 3 (NC devolvio mercaderia)
        $this->assertEquals(3, $product->fresh()->stock);

        // Simulamos que el negocio revendio las 3 unidades a otro cliente
        // (no via SaleService para no crear otra factura — basta con bajar stock
        // directamente, lo cual replica el estado final real).
        //
        // Nota: refresh() obligatorio antes del update. Sin refresh el modelo
        // local tiene stock=0 en memoria (snapshot pre-NC); Eloquent no detecta
        // cambios dirty al asignar 0 y omite el UPDATE — la BD quedaria en 3.
        $product->refresh();
        $product->update(['stock' => 0]);
        $this->assertEquals(0, $product->fresh()->stock);

        try {
            $this->service->voidNotaCredito($nc->fresh());
            $this->fail('Se esperaba StockInsuficienteParaAnularNCException');
        } catch (StockInsuficienteParaAnularNCException $e) {
            $this->assertEquals($product->id, $e->productId);
            $this->assertEquals(3, $e->requerido);
            $this->assertEquals(0, $e->disponible);
        }

        // Rollback verificado: estado atomico preservado.
        $this->assertFalse($nc->fresh()->is_void, 'La NC no debio quedar anulada');
        $this->assertEquals(0, $product->fresh()->stock, 'El stock no debio modificarse');
        $this->assertDatabaseMissing('inventory_movements', [
            'reference_type' => CreditNote::class,
            'reference_id'   => $nc->id,
            'type'           => MovementType::SalidaAnulacionNotaCredito->value,
        ]);
    }

    public function test_anular_nc_preserva_integrity_hash(): void
    {
        // Contrato: el hash sellado al emitir NO incluye is_void, asi que anular
        // no debe recalcularlo. Un QR impreso sigue resolviendo y el verify
        // publico muestra banner "ANULADA".
        [$invoice] = $this->sellAndInvoice(quantity: 1);
        $saleItem  = $invoice->sale->items->first();

        $nc = $this->service->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $invoice,
            reason:  CreditNoteReason::DevolucionFisica,
            lineas:  [new LineaAcreditarInput($saleItem->id, 1)],
        ));

        $hashAntes = $nc->integrity_hash;
        $this->assertNotEmpty($hashAntes);

        $this->service->voidNotaCredito($nc);

        $this->assertEquals($hashAntes, $nc->fresh()->integrity_hash);
    }
}

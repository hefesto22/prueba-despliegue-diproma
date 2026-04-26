<?php

namespace Tests\Feature\Services;

use App\Enums\TaxType;
use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\Resolvers\CorrelativoCentralizado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $service;
    private CompanySetting $company;
    private Establishment $matriz;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('company_settings');

        // Forzar modo centralizado para los tests deterministas
        config(['invoicing.mode' => 'centralizado']);

        $this->company = CompanySetting::factory()->create([
            'legal_name' => 'Diproma S. de R.L.',
            'trade_name' => 'Diproma',
            'rtn' => '08011999000001',
            'address' => 'Barrio Guamilito, 5ta Ave',
            'phone' => '2550-0000',
            'email' => 'diproma@test.com',
        ]);

        // Calentar cache para que CompanySetting::current() retorne esta instancia
        // (evita que firstOrCreate(['id' => 1]) cree un registro diferente si
        // el auto-increment de MySQL avanzó por tests previos).
        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        // Resolver la binding real via container (prueba integración de AppServiceProvider)
        $this->service = app(InvoiceService::class);
    }

    private function makeCompletedSale(): Sale
    {
        // Convención del dominio: SaleItem.unit_price INCLUYE ISV (como lo ve
        // el cliente en el ticket). SaleTaxCalculator lo descompone en base+isv.
        // Ejemplo: unit_price=1150 gravado 15% → base=1000, isv=150, total=1150.
        $sale = Sale::factory()->completada()->create([
            'subtotal' => 1000,
            'isv' => 150,
            'total' => 1150,
            'discount_amount' => 0,
        ]);

        $product = Product::factory()->create();
        SaleItem::factory()->forSale($sale)->forProduct($product)->create([
            'quantity' => 1,
            'unit_price' => 1150, // con ISV → base 1000
            'tax_type' => TaxType::Gravado15,
        ]);

        return $sale->fresh();
    }

    public function test_emite_factura_con_snapshot_fiscal_completo(): void
    {
        CaiRange::factory()->active()->create([
            'prefix' => '001-001-01',
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 0,
        ]);

        $sale = $this->makeCompletedSale();

        $invoice = $this->service->generateFromSale($sale);

        $this->assertEquals('001-001-01-00000001', $invoice->invoice_number);
        $this->assertFalse($invoice->without_cai);
        $this->assertEquals($this->matriz->id, $invoice->establishment_id);
        $this->assertEquals($this->matriz->emission_point, $invoice->emission_point);

        // Snapshot del emisor
        $this->assertEquals('Diproma', $invoice->company_name);
        $this->assertEquals('08011999000001', $invoice->company_rtn);
        $this->assertStringContainsString('Guamilito', $invoice->company_address);

        // Totales
        $this->assertEquals(1000.00, (float) $invoice->subtotal);
        $this->assertEquals(150.00, (float) $invoice->isv);
        $this->assertEquals(1150.00, (float) $invoice->total);
    }

    public function test_avanza_correlativo_del_cai_al_emitir(): void
    {
        $cai = CaiRange::factory()->active()->create([
            'prefix' => '001-001-01',
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 42,
        ]);

        $sale = $this->makeCompletedSale();
        $invoice = $this->service->generateFromSale($sale);

        $cai->refresh();
        $this->assertEquals(43, $cai->current_number);
        $this->assertEquals('001-001-01-00000043', $invoice->invoice_number);
        $this->assertEquals($cai->id, $invoice->cai_range_id);
    }

    public function test_emite_factura_sin_cai_con_referencia_interna(): void
    {
        // Sin CAI activo, pero se pide withoutCai=true
        $sale = $this->makeCompletedSale();

        $invoice = $this->service->generateFromSale($sale, withoutCai: true);

        $this->assertTrue($invoice->without_cai);
        $this->assertNull($invoice->cai);
        $this->assertNull($invoice->cai_range_id);
        $this->assertStringStartsWith('SC-', $invoice->invoice_number);
        $this->assertStringContainsString($sale->sale_number, $invoice->invoice_number);

        // Fallback a matriz para establecimiento
        $this->assertEquals($this->matriz->id, $invoice->establishment_id);
        $this->assertEquals($this->matriz->emission_point, $invoice->emission_point);
    }

    public function test_anula_factura_marcando_is_void(): void
    {
        CaiRange::factory()->active()->create([
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 0,
        ]);

        $sale = $this->makeCompletedSale();
        $invoice = $this->service->generateFromSale($sale);

        $this->assertFalse($invoice->is_void);

        $this->service->voidInvoice($invoice);

        $invoice->refresh();
        $this->assertTrue($invoice->is_void);
    }

    public function test_calcula_desglose_gravado_y_exento(): void
    {
        CaiRange::factory()->active()->create([
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 0,
        ]);

        // Venta con un item gravado y uno exento.
        //   Item gravado: unit_price=1150 (con ISV) → base 1000 + isv 150
        //   Item exento:  unit_price=500 (sin ISV por exención) → base 500
        //   Sale.subtotal = 1000 + 500 = 1500 (suma de bases post-descuento)
        //   Sale.total    = 1500 + 150 = 1650
        $sale = Sale::factory()->completada()->create([
            'subtotal' => 1500,
            'isv' => 150,
            'total' => 1650,
            'discount_amount' => 0,
        ]);

        $product = Product::factory()->create();
        SaleItem::factory()->forSale($sale)->forProduct($product)->create([
            'quantity' => 1,
            'unit_price' => 1150, // con ISV → base 1000
            'tax_type' => TaxType::Gravado15,
        ]);
        SaleItem::factory()->forSale($sale)->forProduct($product)->create([
            'quantity' => 1,
            'unit_price' => 500,  // exento → base = unit_price
            'tax_type' => TaxType::Exento,
        ]);

        $invoice = $this->service->generateFromSale($sale->fresh());

        // Post refactor E.2.A4: taxable_total = SOLO gravado, exempt_total = SOLO exento.
        // Invariante: taxable_total + exempt_total == subtotal.
        $this->assertEquals(1000.00, (float) $invoice->taxable_total);
        $this->assertEquals(500.00, (float) $invoice->exempt_total);
        $this->assertEquals(1500.00, (float) $invoice->subtotal);
        $this->assertEquals(150.00, (float) $invoice->isv);
        $this->assertEquals(1650.00, (float) $invoice->total);
    }

    public function test_usa_consumidor_final_cuando_no_hay_nombre_de_cliente(): void
    {
        CaiRange::factory()->active()->create([
            'range_start' => 1,
            'range_end' => 100,
            'current_number' => 0,
        ]);

        $sale = Sale::factory()->completada()->consumidorFinal()->create([
            'customer_name' => null,
            'subtotal' => 500,
            'isv' => 75,
            'total' => 575,
            'discount_amount' => 0,
        ]);

        $product = Product::factory()->create();
        SaleItem::factory()->forSale($sale)->forProduct($product)->create([
            'quantity' => 1,
            'unit_price' => 500,
            'tax_type' => TaxType::Gravado15,
        ]);

        $invoice = $this->service->generateFromSale($sale->fresh());

        $this->assertEquals('Consumidor Final', $invoice->customer_name);
        $this->assertNull($invoice->customer_rtn);
    }

    public function test_resolver_se_inyecta_via_container(): void
    {
        // Verificar que AppServiceProvider realmente resuelve la interfaz
        // al CorrelativoCentralizado cuando INVOICING_MODE=centralizado
        $resolver = app(\App\Services\Invoicing\Contracts\ResuelveCorrelativoFactura::class);

        $this->assertInstanceOf(CorrelativoCentralizado::class, $resolver);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Invoicing;

use App\Enums\CreditNoteReason;
use App\Enums\PaymentMethod;
use App\Enums\TaxType;
use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\CreditNotes\CreditNotePrintService;
use App\Services\CreditNotes\CreditNoteService;
use App\Services\CreditNotes\DTOs\EmitirNotaCreditoInput;
use App\Services\CreditNotes\DTOs\LineaAcreditarInput;
use App\Services\Invoicing\InvoicePrintService;
use App\Services\Invoicing\InvoiceService;
use App\Services\Sales\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Líneas de factura congeladas (2026-10-01).
 *
 * Una reimpresión de factura o nota de crédito debe salir idéntica a la
 * original aunque después se edite el producto: el nombre y la descripción
 * impresa se congelan en sale_items al vender.
 */
class InvoiceLineSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const CAMARA_DETALLE = "4MP, lente 2.8mm\nIR 30m, IP67";

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('company_settings');
        config(['invoicing.mode' => 'centralizado']);

        $company = CompanySetting::factory()->create(['rtn' => '08011999000001']);
        Cache::put('company_settings', $company, 60 * 60 * 24);

        $matriz = Establishment::factory()->for($company, 'companySetting')->main()->create();

        $cajero = User::factory()->create();
        $this->actingAs($cajero);
        app(CashSessionService::class)->open(
            establishmentId: $matriz->id,
            openedBy: $cajero,
            openingAmount: 1000.00,
        );

        foreach (['01' => '001-001-01', '03' => '001-001-03'] as $documentType => $prefix) {
            CaiRange::factory()->active()->create([
                'prefix' => $prefix,
                'document_type' => $documentType,
                'range_start' => 1,
                'range_end' => 100,
                'current_number' => 0,
            ]);
        }
    }

    /**
     * @param  list<Product>  $products
     */
    private function sellAndInvoice(array $products): Invoice
    {
        $sale = app(SaleService::class)->processSale(
            cartItems: array_map(fn (Product $product): array => [
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 1150.00,
                'tax_type' => TaxType::Gravado15->value,
            ], $products),
            paymentMethod: PaymentMethod::Efectivo,
            customerName: 'Cliente Test',
            customerRtn: '08011999000999',
        );

        return app(InvoiceService::class)->generateFromSale($sale->fresh(['items']));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function printedLines(Invoice $invoice): array
    {
        return app(InvoicePrintService::class)->buildPrintPayload($invoice->fresh())['items'];
    }

    private function camara(bool $printDescription): Product
    {
        return Product::factory()->create([
            'stock' => 10,
            'cost_price' => 500,
            'tax_type' => TaxType::Gravado15,
            'description' => self::CAMARA_DETALLE,
            'print_description' => $printDescription,
        ]);
    }

    public function test_la_reimpresion_conserva_el_nombre_aunque_se_edite_el_producto(): void
    {
        $producto = $this->camara(printDescription: false);
        $nombreOriginal = $producto->name;
        $factura = $this->sellAndInvoice([$producto]);

        $producto->update(['model' => 'OTRO MODELO']);
        $this->assertNotSame($nombreOriginal, $producto->fresh()->name, 'Precondición: el nombre de la ficha cambió.');

        $this->assertSame($nombreOriginal, $this->printedLines($factura)[0]['description']);
    }

    public function test_la_descripcion_se_imprime_solo_si_la_ficha_lo_pide(): void
    {
        $conDescripcion = $this->camara(printDescription: true);
        $sinDescripcion = $this->camara(printDescription: false);

        $lineas = collect($this->printedLines($this->sellAndInvoice([$conDescripcion, $sinDescripcion])))
            ->keyBy('description');

        $this->assertSame(self::CAMARA_DETALLE, $lineas[$conDescripcion->name]['detail']);
        $this->assertNull($lineas[$sinDescripcion->name]['detail']);
    }

    public function test_la_factura_impresa_muestra_la_descripcion_debajo_del_nombre(): void
    {
        $factura = $this->sellAndInvoice([$this->camara(printDescription: true)]);

        $html = view('invoices.print', app(InvoicePrintService::class)->buildPrintPayload($factura->fresh()))->render();

        $this->assertStringContainsString('<span class="item-detail">'.e(self::CAMARA_DETALLE).'</span>', $html);
    }

    public function test_cambiar_la_ficha_despues_no_altera_la_factura_emitida(): void
    {
        $producto = $this->camara(printDescription: true);
        $factura = $this->sellAndInvoice([$producto]);

        $producto->update(['print_description' => false, 'description' => 'Otra descripción']);

        $this->assertSame(self::CAMARA_DETALLE, $this->printedLines($factura)[0]['detail']);
    }

    public function test_la_nota_de_credito_repite_nombre_y_descripcion_de_la_factura(): void
    {
        $producto = $this->camara(printDescription: true);
        $nombreOriginal = $producto->name;
        $factura = $this->sellAndInvoice([$producto]);

        $nota = app(CreditNoteService::class)->generateFromInvoice(new EmitirNotaCreditoInput(
            invoice: $factura->fresh(['sale.items']),
            reason: CreditNoteReason::DevolucionFisica,
            lineas: [new LineaAcreditarInput($factura->sale->items->first()->id, 1)],
        ));

        $producto->update(['model' => 'OTRO MODELO', 'description' => 'Otra descripción']);

        $linea = app(CreditNotePrintService::class)->buildPrintPayload($nota->fresh())['items'][0];
        $this->assertSame($nombreOriginal, $linea['description']);
        $this->assertSame(self::CAMARA_DETALLE, $linea['detail']);
    }
}

<?php

namespace Tests\Feature\Http;

use App\Enums\TaxType;
use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ruta publica /facturas/verificar/{hash} → InvoiceVerificationController.
 *
 * Validaciones:
 *   1. Hash mal formado (no hex de 64 chars) → 404 por regex constraint,
 *      SIN tocar la base de datos (defensa temprana).
 *   2. Hash bien formado pero inexistente → 404 via firstOrFail.
 *   3. Hash valido → 200 + banner "FACTURA VÁLIDA" + watermark + datos.
 *   4. Factura anulada → banner "FACTURA ANULADA" (rojo) en vez de valida.
 *   5. NO requiere autenticacion (es la clave del QR publico).
 */
class InvoiceVerificationRouteTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('company_settings');
        config(['invoicing.mode' => 'centralizado']);
        config(['fiscal.verify_url_base' => 'http://localhost']);

        $company = CompanySetting::factory()->create([
            'legal_name' => 'Diproma S. de R.L.',
            'trade_name' => 'Diproma',
            'rtn'        => '08011999000001',
            'address'    => 'Barrio Guamilito, SPS',
            'phone'      => '2550-0000',
            'email'      => 'diproma@test.com',
        ]);
        Cache::put('company_settings', $company, 60 * 60 * 24);

        Establishment::factory()->for($company, 'companySetting')->main()->create();

        CaiRange::factory()->active()->create([
            'prefix'         => '001-001-01',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 0,
        ]);

        $sale = Sale::factory()->completada()->create([
            'subtotal'        => 1000,
            'isv'             => 150,
            'total'           => 1150,
            'discount_amount' => 0,
        ]);
        // Convención del dominio: SaleItem.unit_price INCLUYE ISV — ver comentario
        // en InvoicePrintRouteTest. 1150 con ISV = base 1000 + isv 150, consistente
        // con los totales del Sale fixture (subtotal=1000, isv=150, total=1150).
        SaleItem::factory()
            ->forSale($sale)
            ->forProduct(Product::factory()->create())
            ->create([
                'quantity'   => 1,
                'unit_price' => 1150,
                'tax_type'   => TaxType::Gravado15,
            ]);

        $this->invoice = app(InvoiceService::class)->generateFromSale($sale->fresh());
    }

    #[Test]
    public function invoice_has_integrity_hash_after_emission(): void
    {
        // Precondicion: InvoiceService debe sellar la factura con un SHA-256.
        // Si este test falla, todos los demas tambien fallan — diagnostico rapido.
        $this->assertNotEmpty($this->invoice->integrity_hash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->invoice->integrity_hash);
    }

    #[Test]
    public function returns_200_for_valid_hash_with_valida_banner(): void
    {
        $response = $this->get("/facturas/verificar/{$this->invoice->integrity_hash}");

        $response->assertOk();
        $response->assertSee('Factura Válida', false);
        // El template usa capitalización de título ("Verificación Pública") en
        // watermark, <title> y aviso SAR — el CAPS LOCK original fue parte de
        // un diseño anterior. Asertamos contra el literal real del HTML.
        $response->assertSee('Verificación Pública', false);
        $response->assertSee($this->invoice->invoice_number);
        $response->assertSee($this->invoice->integrity_hash); // hash visible al pie
    }

    #[Test]
    public function returns_anulada_banner_when_invoice_is_voided(): void
    {
        $this->invoice->void();

        $response = $this->get("/facturas/verificar/{$this->invoice->integrity_hash}");

        $response->assertOk();
        $response->assertSee('Factura Anulada', false);
        $response->assertDontSee('Factura Válida', false);
    }

    #[Test]
    public function voiding_does_not_invalidate_the_printed_qr_hash(): void
    {
        // Contrato: el hash se calcula al emitir y NO incluye is_void,
        // por lo que un QR impreso en factura previamente emitida sigue
        // resolviendo despues de anulada (muestra banner ANULADA).
        $hashAntesDeAnular = $this->invoice->integrity_hash;

        $this->invoice->void();
        $this->invoice->refresh();

        $this->assertSame($hashAntesDeAnular, $this->invoice->integrity_hash);
    }

    #[Test]
    public function returns_404_for_nonexistent_but_well_formed_hash(): void
    {
        $fakeHash = str_repeat('0', 64); // 64 hex chars validos, pero no existe en BD

        $this->get("/facturas/verificar/{$fakeHash}")->assertNotFound();
    }

    #[Test]
    public function returns_404_for_malformed_hash_via_regex_constraint(): void
    {
        // El regex `[a-f0-9]{64}` en la ruta rechaza antes de golpear la BD.
        // Casos: hash corto, caracteres invalidos, payload de ataque tipico.
        $this->get('/facturas/verificar/short')->assertNotFound();
        $this->get('/facturas/verificar/' . str_repeat('z', 64))->assertNotFound();
        $this->get('/facturas/verificar/' . str_repeat('a', 63))->assertNotFound();
        $this->get('/facturas/verificar/' . str_repeat('a', 65))->assertNotFound();
    }

    #[Test]
    public function public_route_does_not_require_authentication(): void
    {
        // Sin actingAs — la ruta debe responder 200.
        $response = $this->get("/facturas/verificar/{$this->invoice->integrity_hash}");

        $response->assertOk();
        $response->assertDontSee('login'); // no redirect a auth
    }
}

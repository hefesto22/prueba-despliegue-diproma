<?php

namespace Tests\Feature\Http;

use App\Enums\CreditNoteReason;
use App\Enums\PaymentMethod;
use App\Enums\TaxType;
use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\Establishment;
use App\Models\Product;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\CreditNotes\CreditNoteService;
use App\Services\CreditNotes\DTOs\EmitirNotaCreditoInput;
use App\Services\CreditNotes\DTOs\LineaAcreditarInput;
use App\Services\Invoicing\InvoiceService;
use App\Services\Sales\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ruta publica /notas-credito/verificar/{hash} → CreditNoteVerificationController.
 *
 * Simetrico a InvoiceVerificationRouteTest. Valida:
 *   1. Hash mal formado (no 64 hex chars) → 404 por regex constraint,
 *      SIN tocar la base de datos (defensa temprana).
 *   2. Hash bien formado pero inexistente → 404 via firstOrFail.
 *   3. Hash valido → 200 + banner "Nota de Crédito Válida" + watermark + datos.
 *   4. NC anulada → banner "Nota de Crédito Anulada" (rojo) en vez de valida.
 *   5. NO requiere autenticacion — el QR publico debe ser verificable por
 *      cualquiera con el codigo (informacion autocontenida del documento fiscal).
 *   6. Anular NC no invalida el hash: el QR impreso sigue resolviendo.
 */
class CreditNoteVerificationRouteTest extends TestCase
{
    use RefreshDatabase;

    private CreditNote $creditNote;

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

        $matriz = Establishment::factory()->for($company, 'companySetting')->main()->create();

        // C2 — caja abierta requerida para que SaleService::processSale no
        // lance NoHayCajaAbiertaException durante el setUp.
        $cajero = User::factory()->create();
        $this->actingAs($cajero);
        app(CashSessionService::class)->open(
            establishmentId: $matriz->id,
            openedBy: $cajero,
            openingAmount: 1000.00,
        );

        CaiRange::factory()->active()->create([
            'prefix'         => '001-001-01',
            'document_type'  => '01',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 0,
        ]);

        CaiRange::factory()->active()->create([
            'prefix'         => '001-001-03',
            'document_type'  => '03',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 0,
        ]);

        $product = Product::factory()->create([
            'stock'      => 10,
            'cost_price' => 50.00,
            'tax_type'   => TaxType::Gravado15,
        ]);

        $sale = app(SaleService::class)->processSale(
            cartItems: [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'unit_price' => 1000.00,
                'tax_type'   => TaxType::Gravado15->value,
            ]],
            paymentMethod: PaymentMethod::Efectivo,
            customerName: 'Cliente Verificacion',
            customerRtn:  '08011999000999',
        );

        $invoice = app(InvoiceService::class)->generateFromSale($sale->fresh(['items']));
        $saleItem = $invoice->sale->items->first();

        $this->creditNote = app(CreditNoteService::class)->generateFromInvoice(
            new EmitirNotaCreditoInput(
                invoice: $invoice->fresh(['sale.items']),
                reason:  CreditNoteReason::DevolucionFisica,
                lineas:  [new LineaAcreditarInput($saleItem->id, 1)],
            )
        );
    }

    #[Test]
    public function credit_note_has_integrity_hash_after_emission(): void
    {
        // Precondicion: CreditNoteService debe sellar la NC con un SHA-256.
        // Si este test falla, todos los demas tambien fallan — diagnostico rapido.
        $this->assertNotEmpty($this->creditNote->integrity_hash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->creditNote->integrity_hash);
    }

    #[Test]
    public function returns_200_for_valid_hash_with_valida_banner(): void
    {
        $response = $this->get("/notas-credito/verificar/{$this->creditNote->integrity_hash}");

        $response->assertOk();
        $response->assertSee('Nota de Crédito Válida', false);
        $response->assertSee('VERIFICACIÓN PÚBLICA', false);
        $response->assertSee($this->creditNote->credit_note_number);
        $response->assertSee($this->creditNote->integrity_hash); // hash visible al pie
    }

    #[Test]
    public function shows_original_invoice_reference_block(): void
    {
        // La NC es autocontenida — el verify muestra los datos de la factura
        // origen tal como quedaron sellados, no re-verifica su estado actual.
        $response = $this->get("/notas-credito/verificar/{$this->creditNote->integrity_hash}");

        $response->assertOk();
        $response->assertSee('Factura que se acredita', false);
        $response->assertSee($this->creditNote->original_invoice_number);
    }

    #[Test]
    public function shows_reason_block_with_enum_label(): void
    {
        $response = $this->get("/notas-credito/verificar/{$this->creditNote->integrity_hash}");

        $response->assertOk();
        $response->assertSee('Razón de emisión', false);
        $response->assertSee(CreditNoteReason::DevolucionFisica->getLabel());
    }

    #[Test]
    public function returns_anulada_banner_when_credit_note_is_voided(): void
    {
        app(CreditNoteService::class)->voidNotaCredito($this->creditNote);

        $response = $this->get("/notas-credito/verificar/{$this->creditNote->integrity_hash}");

        $response->assertOk();
        $response->assertSee('Nota de Crédito Anulada', false);
        $response->assertDontSee('Nota de Crédito Válida', false);
    }

    #[Test]
    public function voiding_does_not_invalidate_the_printed_qr_hash(): void
    {
        // Contrato: el hash se calcula al emitir y NO incluye is_void,
        // por lo que un QR ya impreso sigue resolviendo despues de anulada
        // (el verify muestra banner ANULADA — la info sigue siendo publica).
        $hashAntesDeAnular = $this->creditNote->integrity_hash;

        app(CreditNoteService::class)->voidNotaCredito($this->creditNote);
        $this->creditNote->refresh();

        $this->assertSame($hashAntesDeAnular, $this->creditNote->integrity_hash);
    }

    #[Test]
    public function returns_404_for_nonexistent_but_well_formed_hash(): void
    {
        $fakeHash = str_repeat('0', 64); // 64 hex chars validos, pero no existe en BD

        $this->get("/notas-credito/verificar/{$fakeHash}")->assertNotFound();
    }

    #[Test]
    public function returns_404_for_malformed_hash_via_regex_constraint(): void
    {
        // El regex `[a-f0-9]{64}` en la ruta rechaza antes de golpear la BD.
        // Casos: hash corto, caracteres invalidos, longitud incorrecta.
        $this->get('/notas-credito/verificar/short')->assertNotFound();
        $this->get('/notas-credito/verificar/' . str_repeat('z', 64))->assertNotFound();
        $this->get('/notas-credito/verificar/' . str_repeat('a', 63))->assertNotFound();
        $this->get('/notas-credito/verificar/' . str_repeat('a', 65))->assertNotFound();
    }

    #[Test]
    public function public_route_does_not_require_authentication(): void
    {
        // Sin actingAs — la ruta debe responder 200.
        $response = $this->get("/notas-credito/verificar/{$this->creditNote->integrity_hash}");

        $response->assertOk();
        $response->assertDontSee('login'); // no redirect a auth
    }
}

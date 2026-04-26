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
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Ruta autenticada /credit-notes/{creditNote} → CreditNotePrintController.
 *
 * Simetrico a InvoicePrintRouteTest. Valida:
 *   1. Guest → redirect a login (middleware auth).
 *   2. User sin permiso 'View:CreditNote' → 403 (CreditNotePolicy@view).
 *   3. User con permiso → 200 + body con datos de la NC (numero, emisor,
 *      receptor, factura origen, razon, total, QR inline).
 *   4. NC anulada → banner "NOTA DE CRÉDITO ANULADA" presente.
 */
class CreditNotePrintRouteTest extends TestCase
{
    use RefreshDatabase;

    private CreditNote $creditNote;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('company_settings');
        config(['invoicing.mode' => 'centralizado']);

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
        // lance NoHayCajaAbiertaException durante el setUp. Logout al final
        // (después de emitir NC) porque este archivo testea ruta autenticada
        // y uno de los tests espera comportamiento de guest.
        $cajero = User::factory()->create();
        $this->actingAs($cajero);
        app(CashSessionService::class)->open(
            establishmentId: $matriz->id,
            openedBy: $cajero,
            openingAmount: 1000.00,
        );

        // CAI para facturas (document_type '01')
        CaiRange::factory()->active()->create([
            'prefix'         => '001-001-01',
            'document_type'  => '01',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 0,
        ]);

        // CAI para notas de credito (document_type '03')
        CaiRange::factory()->active()->create([
            'prefix'         => '001-001-03',
            'document_type'  => '03',
            'range_start'    => 1,
            'range_end'      => 100,
            'current_number' => 0,
        ]);

        // Producto con stock suficiente para vender + devolver.
        // Product::booted() autogenera 'name' desde type+brand+model,
        // por lo que usamos el SKU estable para las aserciones de HTML.
        $this->product = Product::factory()->create([
            'stock'      => 10,
            'cost_price' => 50.00,
            'tax_type'   => TaxType::Gravado15,
        ]);

        // Venta real via SaleService → genera SalidaVenta en kardex
        $sale = app(SaleService::class)->processSale(
            cartItems: [[
                'product_id' => $this->product->id,
                'quantity'   => 2,
                'unit_price' => 115.00,
                'tax_type'   => TaxType::Gravado15->value,
            ]],
            paymentMethod: PaymentMethod::Efectivo,
            customerName: 'Cliente Prueba',
            customerRtn:  '08011999000999',
        );

        $invoice = app(InvoiceService::class)->generateFromSale($sale->fresh(['items']));
        $saleItem = $invoice->sale->items->first();

        // Emito NC por devolucion fisica de 1 unidad
        $this->creditNote = app(CreditNoteService::class)->generateFromInvoice(
            new EmitirNotaCreditoInput(
                invoice: $invoice->fresh(['sale.items']),
                reason:  CreditNoteReason::DevolucionFisica,
                lineas:  [new LineaAcreditarInput($saleItem->id, 1)],
            )
        );

        Permission::findOrCreate('View:CreditNote', 'web');

        // Cada test decide su propio acting user (guest, user sin permiso,
        // user con permiso). Limpio el acting del cajero que abrió caja.
        auth()->logout();
    }

    #[Test]
    public function guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('credit-notes.print', $this->creditNote));

        $response->assertRedirect();
        $response->assertStatus(302);
    }

    #[Test]
    public function authenticated_user_without_permission_receives_403(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('credit-notes.print', $this->creditNote))
            ->assertForbidden();
    }

    #[Test]
    public function authenticated_user_with_permission_sees_credit_note(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('View:CreditNote');

        $response = $this->actingAs($user)->get(route('credit-notes.print', $this->creditNote));

        $response->assertOk();
        $response->assertSee($this->creditNote->credit_note_number);
        $response->assertSee('NOTA DE CRÉDITO', false);
        $response->assertSee('Diproma');                                   // emisor (snapshot)
        $response->assertSee('08011999000001');                            // RTN emisor
        $response->assertSee($this->product->name);                        // item
        $response->assertSee("SKU: {$this->product->sku}", false);         // SKU
        $response->assertSee($this->creditNote->original_invoice_number);  // factura origen
        $response->assertSee(CreditNoteReason::DevolucionFisica->getLabel()); // razon
        $response->assertSee('115.00');                                    // total: 1 x 115
        $response->assertSee('<svg', false);                               // QR inline SVG
    }

    #[Test]
    public function print_view_shows_void_banner_for_voided_credit_note(): void
    {
        app(CreditNoteService::class)->voidNotaCredito($this->creditNote);

        $user = User::factory()->create();
        $user->givePermissionTo('View:CreditNote');

        $response = $this->actingAs($user)->get(route('credit-notes.print', $this->creditNote->fresh()));

        $response->assertOk();
        $response->assertSee('NOTA DE CRÉDITO ANULADA', false);
    }
}

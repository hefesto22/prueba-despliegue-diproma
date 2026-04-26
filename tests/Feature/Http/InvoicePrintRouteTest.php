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
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Ruta autenticada /invoices/{invoice} → InvoicePrintController.
 *
 * Validaciones:
 *   1. Guest es redirigido a login (middleware auth).
 *   2. User autenticado SIN permiso 'View:Invoice' recibe 403 (InvoicePolicy@view).
 *   3. User autenticado CON permiso 'View:Invoice' recibe 200 + body con
 *      los datos esperados de la factura (numero, cliente, emisor, QR inline).
 */
class InvoicePrintRouteTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;
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
        // Product::booted() autogenera el name desde type+brand+model, por lo que
        // no sirve pasar 'name' al factory — se ignora. Guardamos el producto
        // creado para poder usar su SKU (estable y unico) en las aserciones del HTML.
        $this->product = Product::factory()->create();

        // Convención del dominio: SaleItem.unit_price INCLUYE ISV (así lo ve el cliente
        // en el ticket). SaleTaxCalculator lo descompone: 1150 → base 1000 + isv 150,
        // lo que hace que el item sea INTERNAMENTE CONSISTENTE con los totales del Sale
        // (subtotal=1000, isv=150, total=1150). Antes del fix E.2.A4 cualquier dato
        // inconsistente pasaba porque el código copiaba Sale.subtotal literal — el
        // calculator actual recalcula desde items y exige consistencia.
        SaleItem::factory()
            ->forSale($sale)
            ->forProduct($this->product)
            ->create([
                'quantity'   => 1,
                'unit_price' => 1150,
                'tax_type'   => TaxType::Gravado15,
            ]);

        $this->invoice = app(InvoiceService::class)->generateFromSale($sale->fresh());

        // Prepare permission records (Shield crea estos en produccion via seed)
        Permission::findOrCreate('View:Invoice', 'web');
    }

    #[Test]
    public function guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('invoices.print', $this->invoice));

        $response->assertRedirect();
        $response->assertStatus(302);
    }

    #[Test]
    public function authenticated_user_without_permission_receives_403(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('invoices.print', $this->invoice))
            ->assertForbidden();
    }

    #[Test]
    public function authenticated_user_with_permission_sees_invoice(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('View:Invoice');

        $response = $this->actingAs($user)->get(route('invoices.print', $this->invoice));

        $response->assertOk();
        $response->assertSee($this->invoice->invoice_number);
        $response->assertSee('Diproma');                    // emisor (snapshot en Invoice)
        $response->assertSee('08011999000001');             // RTN del emisor
        $response->assertSee($this->product->name);         // nombre autogenerado del producto
        // El template imprime el SKU dentro de la columna "Código" como valor
        // puro (<td><span class="sku">TAB-HP-00001</span></td>), sin prefijo
        // literal "SKU:". Verificamos que el SKU aparezca en el HTML; el
        // encabezado de columna "Código" ya le da significado semántico al
        // lector humano.
        $response->assertSee($this->product->sku);          // SKU autogenerado visible en la tabla
        $response->assertSee('1,150.00');                   // total formateado
        $response->assertSee('<svg', false);                // QR inline SVG
    }

    #[Test]
    public function print_view_shows_void_banner_for_voided_invoice(): void
    {
        $this->invoice->void();

        $user = User::factory()->create();
        $user->givePermissionTo('View:Invoice');

        $response = $this->actingAs($user)->get(route('invoices.print', $this->invoice->fresh()));

        $response->assertOk();
        $response->assertSee('FACTURA ANULADA');
    }
}

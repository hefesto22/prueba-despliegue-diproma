<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Purchases;

use App\Enums\SupplierDocumentType;
use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Tests de integración del flujo completo de Recibo Interno a través de la
 * página Filament CreatePurchase.
 *
 * Cubre los invariantes observables del flujo RI:
 *   1. Al crear con document_type=ReciboInterno SIN proveedor seleccionado,
 *      el Purchase cae al genérico "Varios / Sin identificar" como default
 *      operativo, y guarda:
 *      - supplier_invoice_number = correlativo RI-YYYYMMDD-NNNN generado
 *      - supplier_cai = null
 *      - credit_days = 0
 *
 *   2. Al crear con document_type=ReciboInterno CON proveedor real seleccionado,
 *      el Purchase respeta esa elección. Es trazabilidad interna legítima:
 *      "le compré a Comercial El Norte sin factura — quiero saber a quién".
 *      El RI sigue sin entrar al Libro de Compras SAR (eso lo determina el
 *      filtro por document_type en PurchaseBookService, no por supplier_id).
 *
 *   3. Dos RIs creados el mismo día incrementan el correlativo (0001, 0002).
 *      Garantiza que el servicio funciona dentro de la transacción de Filament.
 *
 * NO re-testea la lógica pura del generador (InternalReceiptNumberGenerator)
 * — eso vive en InternalReceiptNumberGeneratorTest. Acá solo verifico la
 * orquestación UI → Service → DB.
 *
 * Nota histórica: hasta 2026-04-25 había un test "defense in depth" que
 * afirmaba que un payload manipulado con supplier_id se sobrescribía al
 * genérico. Esa regla se relajó: ahora el supplier real elegido se respeta
 * (requerimiento de negocio para control interno de proveedores informales).
 */
class CreatePurchaseReciboInternoTest extends TestCase
{
    use RefreshDatabase, CreatesMatriz;

    private User $admin;

    private Supplier $generico;

    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        // El genérico lo crea la migración — si no aparece el módulo RI está roto.
        $this->generico = Supplier::forInternalReceipts();

        // Producto real para el Repeater de items (cualquier producto activo sirve).
        $this->producto = Product::factory()->create([
            'cost_price' => 100.00,
            'is_active' => true,
        ]);

        // Mismo patrón de bypass de Gate que CashSessionResourceTest: evitamos
        // configurar permisos finos por test — el foco está en el flujo operativo.
        Role::firstOrCreate([
            'name' => Utils::getSuperAdminName(),
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Gate::before(function ($user) {
            if ($user instanceof User && $user->hasRole(Utils::getSuperAdminName())) {
                return true;
            }

            return null;
        });

        $this->admin = User::factory()->create([
            'is_active' => true,
            'default_establishment_id' => $this->matriz->id,
        ]);
        $this->admin->assignRole(Utils::getSuperAdminName());
    }

    // ─── Happy path RI ───────────────────────────────────────

    public function test_crear_purchase_con_tipo_recibo_interno_sin_supplier_cae_al_generico(): void
    {
        // Freeze de fecha para que el correlativo sea predecible.
        $hoy = CarbonImmutable::parse('2026-04-19');
        CarbonImmutable::setTestNow($hoy);
        \Carbon\Carbon::setTestNow($hoy);

        $this->actingAs($this->admin);

        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'document_type' => SupplierDocumentType::ReciboInterno->value,
                'establishment_id' => $this->matriz->id,
                'date' => $hoy->toDateString(),
                'items' => [
                    [
                        'product_id' => $this->producto->id,
                        'quantity' => 2,
                        'unit_cost' => 100.00,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $purchase = Purchase::query()->latest('id')->firstOrFail();

        $this->assertSame(
            SupplierDocumentType::ReciboInterno,
            $purchase->document_type,
            'El Purchase debe quedar como Recibo Interno.',
        );
        $this->assertSame($this->generico->id, $purchase->supplier_id,
            'Sin supplier elegido, el RI debe caer al genérico del sistema.');
        $this->assertSame('RI-20260419-0001', $purchase->supplier_invoice_number,
            'El supplier_invoice_number debe ser el primer correlativo del día.');
        $this->assertNull($purchase->supplier_cai,
            'Un RI no tiene CAI por definición.');
        $this->assertSame(0, $purchase->credit_days,
            'Un RI es siempre contado — no hay proveedor con crédito.');

        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
    }

    // ─── RI con supplier real: trazabilidad interna ─────────

    public function test_recibo_interno_con_supplier_real_seleccionado_conserva_el_supplier(): void
    {
        $hoy = CarbonImmutable::parse('2026-04-19');
        CarbonImmutable::setTestNow($hoy);
        \Carbon\Carbon::setTestNow($hoy);

        // Proveedor real (no genérico). El operador lo elige a propósito en un
        // RI para llevar control interno de a quién le compró sin factura.
        // Caso de uso: "Comercial El Norte me vendió sin factura este mes,
        // quiero saber que le compré a ellos aunque no entre al Libro SAR".
        $proveedorReal = Supplier::factory()->create([
            'name' => 'Comercial El Norte',
            'is_generic' => false,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'document_type' => SupplierDocumentType::ReciboInterno->value,
                'supplier_id' => $proveedorReal->id,
                'establishment_id' => $this->matriz->id,
                'date' => $hoy->toDateString(),
                'items' => [
                    [
                        'product_id' => $this->producto->id,
                        'quantity' => 1,
                        'unit_cost' => 50.00,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $purchase = Purchase::query()->latest('id')->firstOrFail();

        // El supplier real se conserva — trazabilidad interna sin afectar SAR.
        $this->assertSame($proveedorReal->id, $purchase->supplier_id,
            'El RI debe conservar el supplier real elegido por el operador.');
        $this->assertNotSame($this->generico->id, $purchase->supplier_id,
            'El RI con supplier real NO debe caer al genérico.');

        // Lo demás del RI sigue intacto: tipo, sin CAI, contado, correlativo RI.
        $this->assertSame(SupplierDocumentType::ReciboInterno, $purchase->document_type);
        $this->assertNull($purchase->supplier_cai,
            'Aunque tenga supplier real, un RI sigue sin CAI por definición.');
        $this->assertSame(0, $purchase->credit_days);
        $this->assertSame('RI-20260419-0001', $purchase->supplier_invoice_number,
            'El correlativo RI-YYYYMMDD-NNNN se asigna igual con o sin supplier real.');

        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
    }

    // ─── Secuencia diaria ───────────────────────────────────

    public function test_dos_RIs_del_mismo_dia_incrementan_el_correlativo(): void
    {
        $hoy = CarbonImmutable::parse('2026-04-19');
        CarbonImmutable::setTestNow($hoy);
        \Carbon\Carbon::setTestNow($hoy);

        $this->actingAs($this->admin);

        // Primer RI del día
        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'document_type' => SupplierDocumentType::ReciboInterno->value,
                'establishment_id' => $this->matriz->id,
                'date' => $hoy->toDateString(),
                'items' => [[
                    'product_id' => $this->producto->id,
                    'quantity' => 1,
                    'unit_cost' => 50.00,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // Segundo RI del día — debe obtener NNNN=0002
        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'document_type' => SupplierDocumentType::ReciboInterno->value,
                'establishment_id' => $this->matriz->id,
                'date' => $hoy->toDateString(),
                'items' => [[
                    'product_id' => $this->producto->id,
                    'quantity' => 1,
                    'unit_cost' => 75.00,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $numeros = Purchase::query()
            ->where('document_type', SupplierDocumentType::ReciboInterno)
            ->orderBy('id')
            ->pluck('supplier_invoice_number')
            ->all();

        $this->assertSame(
            ['RI-20260419-0001', 'RI-20260419-0002'],
            $numeros,
            'Dos RIs consecutivos del mismo día deben incrementar el NNNN.',
        );

        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
    }

    // ─── Control: Factura normal sigue funcionando ──────────

    public function test_crear_factura_normal_no_toca_el_generico(): void
    {
        $this->actingAs($this->admin);

        $proveedor = Supplier::factory()->create(['is_generic' => false]);

        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'document_type' => SupplierDocumentType::Factura->value,
                'supplier_id' => $proveedor->id,
                'establishment_id' => $this->matriz->id,
                'supplier_invoice_number' => '001-001-01-00000001',
                // CAI dentro del maxLength(43) y regex /^[A-F0-9\-]+$/i del form.
                'supplier_cai' => 'ABCDEF-123456-789ABC-DEF012-345678-AB',
                'date' => '2026-04-19',
                'items' => [[
                    'product_id' => $this->producto->id,
                    'quantity' => 1,
                    'unit_cost' => 100.00,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $purchase = Purchase::query()->latest('id')->firstOrFail();

        $this->assertSame($proveedor->id, $purchase->supplier_id);
        $this->assertSame(SupplierDocumentType::Factura, $purchase->document_type);
        $this->assertSame('001-001-01-00000001', $purchase->supplier_invoice_number);
        $this->assertNotNull($purchase->supplier_cai);
    }
}

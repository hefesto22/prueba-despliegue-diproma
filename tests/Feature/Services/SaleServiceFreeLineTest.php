<?php

namespace Tests\Feature\Services;

use App\Authorization\CustomPermission;
use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\TaxType;
use App\Exceptions\Cash\NoHayCajaAbiertaException;
use App\Models\CaiRange;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\User;
use App\Services\Cash\CashBalanceCalculator;
use App\Services\Cash\CashSessionService;
use App\Services\Invoicing\InvoiceService;
use App\Services\Sales\FreeSaleLine;
use App\Services\Sales\SaleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Factura especial — SaleService::processFreeLineSale.
 *
 * Venta con líneas libres (sin catálogo): mismo ciclo que una venta del POS
 * (caja, cliente, totales fiscales) pero sin tocar inventario.
 *
 * Caso real usado en los tests (pedido de Mauricio, 2026-10-27):
 *   - Almohadillas y mantenimiento Epson L350   1,200.00 exento (costo 300)
 *   - Mantenimiento de rodos y limpieza L210      980.00 exento
 *   - Mantenimiento general - reseteo L3110     1,050.00 gravado (con ISV)
 *   Gravado: 1050 / 1.15 = 913.04 base + 136.96 ISV
 *   Subtotal 2,180.00 + 913.04 = 3,093.04 · ISV 136.96 · Total 3,230.00
 */
class SaleServiceFreeLineTest extends TestCase
{
    use CreatesMatriz;
    use RefreshDatabase;

    private SaleService $service;

    private User $emisor;

    private CashSession $caja;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SaleService::class);
        $this->emisor = $this->userWithPermission();
        $this->actingAs($this->emisor);

        $this->caja = app(CashSessionService::class)->open(
            establishmentId: $this->matriz->id,
            openedBy: $this->emisor,
            openingAmount: 1000.00,
        );

        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => null, // modo centralizado
        ]);
    }

    private function userWithPermission(): User
    {
        $role = Role::create(['name' => 'emisor_especial', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate([
            'name' => CustomPermission::IssueSpecialInvoice->value,
            'guard_name' => 'web',
        ]));

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * @return FreeSaleLine[]
     */
    private function lineasEpson(): array
    {
        return [
            new FreeSaleLine('Almohadillas y mantenimiento de epson L350', 1, 1200, TaxType::Exento, unitCost: 300),
            new FreeSaleLine('Mantenimiento de rodos y limpieza epson L210', 1, 980, TaxType::Exento),
            new FreeSaleLine('Mantenimiento general - reseteo epson L3110', 1, 1050, TaxType::Gravado15),
        ];
    }

    private function emitir(
        ?array $lines = null,
        string $rtn = '0501-1990-123456',
        PaymentMethod $method = PaymentMethod::Efectivo,
    ): Sale {
        return $this->service->processFreeLineSale(
            lines: $lines ?? $this->lineasEpson(),
            paymentMethod: $method,
            customerName: 'Taller López',
            customerRtn: $rtn,
            notes: 'Factura especial — casos fuera de manejo de sistema',
        );
    }

    public function test_crea_la_venta_con_lineas_libres_sin_tocar_inventario(): void
    {
        $sale = $this->emitir();

        $this->assertSame(SaleStatus::Completada, $sale->status);
        $this->assertSame('3093.04', $sale->subtotal);
        $this->assertSame('136.96', $sale->isv);
        $this->assertSame('3230.00', $sale->total);
        $this->assertSame('TALLER LÓPEZ', $sale->customer_name);
        $this->assertSame('0501-1990-123456', $sale->customer_rtn);
        $this->assertSame($this->matriz->id, $sale->establishment_id);

        $items = $sale->items()->orderBy('id')->get();
        $this->assertCount(3, $items);
        $this->assertTrue($items->every(fn ($item) => $item->product_id === null));
        $this->assertSame('ALMOHADILLAS Y MANTENIMIENTO DE EPSON L350', $items[0]->description);
        $this->assertSame('ALMOHADILLAS Y MANTENIMIENTO DE EPSON L350', $items[0]->display_name);
        $this->assertSame('300.00', $items[0]->unit_cost);
        $this->assertNull($items[1]->unit_cost);
        $this->assertSame(TaxType::Gravado15, $items[2]->tax_type);
        $this->assertSame('913.04', $items[2]->subtotal);
        $this->assertSame('136.96', $items[2]->isv_amount);
        $this->assertSame('1050.00', $items[2]->total);

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_registra_el_ingreso_en_la_caja_abierta(): void
    {
        $sale = $this->emitir();

        $movement = CashMovement::query()
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame(CashMovementType::SaleIncome, $movement->type);
        $this->assertSame(PaymentMethod::Efectivo, $movement->payment_method);
        $this->assertSame('3230.00', $movement->amount);
        $this->assertSame(4230.00, app(CashBalanceCalculator::class)->expectedCash($this->caja->fresh()));
    }

    public function test_la_factura_cai_separa_gravado_y_exento(): void
    {
        $invoice = DB::transaction(function (): Invoice {
            $sale = $this->emitir();

            return app(InvoiceService::class)->generateFromSale($sale, establishmentId: $this->matriz->id);
        });

        $this->assertNotNull($invoice->cai);
        $this->assertSame('913.04', $invoice->taxable_total);
        $this->assertSame('2180.00', $invoice->exempt_total);
        $this->assertSame('136.96', $invoice->isv);
        $this->assertSame('3230.00', $invoice->total);
    }

    public function test_crea_el_cliente_por_rtn(): void
    {
        $sale = $this->emitir();

        $customer = Customer::query()->where('rtn', '0501-1990-123456')->first();

        $this->assertNotNull($customer);
        $this->assertSame('TALLER LÓPEZ', $customer->name);
        $this->assertSame($customer->id, $sale->customer_id);
    }

    public function test_sin_caja_abierta_no_crea_nada(): void
    {
        app(CashSessionService::class)->close(
            session: $this->caja->fresh(),
            closedBy: $this->emisor,
            actualClosingAmount: 1000.00,
        );

        try {
            $this->emitir();
            $this->fail('Debió exigir caja abierta.');
        } catch (NoHayCajaAbiertaException) {
            $this->assertSame(0, Sale::count());
        }
    }

    public function test_rechaza_rtn_sin_14_digitos(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->emitir(rtn: '0501-1990');
    }

    public function test_rechaza_factura_sin_lineas(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->emitir(lines: []);
    }

    public function test_usuario_sin_permiso_no_puede_emitir(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]));

        try {
            $this->emitir();
            $this->fail('Debió rechazar al usuario sin Issue:SpecialInvoice.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Sale::count());
        }
    }

    public function test_anular_cuadra_la_caja_y_anula_la_factura(): void
    {
        $invoice = DB::transaction(function (): Invoice {
            $sale = $this->emitir();

            return app(InvoiceService::class)->generateFromSale($sale, establishmentId: $this->matriz->id);
        });

        $sale = $invoice->sale()->firstOrFail();

        $this->service->cancel($sale);

        $cancellation = CashMovement::query()
            ->where('type', CashMovementType::SaleCancellation)
            ->where('reference_id', $sale->id)
            ->first();

        $this->assertNotNull($cancellation);
        $this->assertSame('3230.00', $cancellation->amount);
        $this->assertSame(1000.00, app(CashBalanceCalculator::class)->expectedCash($this->caja->fresh()));
        $this->assertTrue($invoice->fresh()->is_void);
        $this->assertSame(SaleStatus::Anulada, $sale->fresh()->status);
        $this->assertSame(0, InventoryMovement::count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Expenses;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\Expense;
use App\Models\FiscalPeriod;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Expenses\ExpenseService;
use App\Services\FiscalBooks\PurchaseBookService;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalCerradoException;
use App\Services\Purchases\Exceptions\FacturaYaRegistradaException;
use App\Services\Purchases\Exceptions\MontosDocumentoInvalidosException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Fase 1b — un gasto con factura con CAI mantiene su copia en Compras.
 *
 * Se prueba por la puerta real (ExpenseService::register / updateFiscalData)
 * porque el contrato importante es la atomicidad: gasto y compra entran o
 * fallan juntos. Pago por transferencia para no depender de una caja abierta.
 */
class ExpenseFiscalDocumentSyncTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseService $service;

    private Establishment $matriz;

    private User $contador;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-20 10:00:00');
        Carbon::setTestNow('2026-09-20 10:00:00');

        Cache::forget('company_settings');
        $company = CompanySetting::factory()->create(['fiscal_period_start' => '2026-01-01']);
        Cache::put('company_settings', $company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()->for($company, 'companySetting')->main()->create();
        $this->contador = User::factory()->create();
        $this->service = app(ExpenseService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function gastoConFactura(array $overrides = []): array
    {
        return [
            'establishment_id' => $this->matriz->id,
            'user_id' => $this->contador->id,
            'expense_date' => '2026-09-18',
            'category' => ExpenseCategory::Papeleria,
            'payment_method' => PaymentMethod::Transferencia,
            'amount_total' => 1150.00,
            'taxable_amount' => 1000.00,
            'isv_amount' => 150.00,
            'is_isv_deductible' => true,
            'description' => 'Resmas y tóner',
            'provider_name' => 'Librería Universal',
            'provider_rtn' => '08019970000001',
            'provider_invoice_number' => '001-001-01-00012345',
            'provider_invoice_cai' => 'ABCDEF-123456-789ABC-DEF012-345678-AB',
            'provider_invoice_date' => '2026-09-17',
            ...$overrides,
        ];
    }

    // ─── Registrar ───────────────────────────────────────────

    public function test_gasto_con_factura_crea_su_compra_confirmada_en_el_libro(): void
    {
        $expense = $this->service->register($this->gastoConFactura());

        $purchase = $expense->fiscalDocument;
        $this->assertNotNull($purchase, 'El gasto con factura debe tener su copia en Compras.');
        $this->assertSame(PurchaseStatus::Confirmada, $purchase->status);
        $this->assertSame(PaymentStatus::Pagada, $purchase->payment_status);
        $this->assertSame(SupplierDocumentType::Factura, $purchase->document_type);
        $this->assertSame('001-001-01-00012345', $purchase->supplier_invoice_number);
        $this->assertSame('ABCDEF-123456-789ABC-DEF012-345678-AB', $purchase->supplier_cai);
        $this->assertSame('2026-09-17', $purchase->date->toDateString(), 'El libro va por la fecha de la factura.');
        $this->assertSame($this->matriz->id, $purchase->establishment_id);
        $this->assertEquals(1000.00, (float) $purchase->taxable_total);
        $this->assertEquals(0.00, (float) $purchase->exempt_total);
        $this->assertEquals(150.00, (float) $purchase->isv);
        $this->assertEquals(1150.00, (float) $purchase->total);

        $book = app(PurchaseBookService::class)->build(2026, 9);
        $this->assertEquals(150.00, $book->summary->creditoFiscalNeto(),
            'El ISV del gasto debe llegar al crédito fiscal del Libro de Compras.');
    }

    public function test_el_exento_se_deriva_del_total_del_gasto(): void
    {
        $expense = $this->service->register($this->gastoConFactura(['amount_total' => 1400.00]));

        $this->assertEquals(250.00, (float) $expense->fiscalDocument->exempt_total);
        $this->assertEquals(1400.00, (float) $expense->fiscalDocument->total);
    }

    public function test_sin_fecha_de_factura_usa_la_fecha_del_gasto(): void
    {
        $expense = $this->service->register($this->gastoConFactura(['provider_invoice_date' => null]));

        $this->assertSame('2026-09-18', $expense->fiscalDocument->date->toDateString());
    }

    public function test_gasto_sin_factura_no_crea_compra(): void
    {
        $this->service->register($this->gastoConFactura(['is_isv_deductible' => false]));

        $this->assertSame(0, Purchase::count());
    }

    // ─── Proveedor ───────────────────────────────────────────

    public function test_crea_el_proveedor_si_el_rtn_no_existe(): void
    {
        $expense = $this->service->register($this->gastoConFactura());

        $supplier = $expense->fiscalDocument->supplier;
        $this->assertSame('08019970000001', $supplier->rtn);
        $this->assertSame('Librería Universal', $supplier->name);
    }

    public function test_reutiliza_el_proveedor_existente_por_rtn(): void
    {
        $existente = Supplier::factory()->create(['rtn' => '08019970000001', 'name' => 'LIBRERIA UNIVERSAL S.A.']);

        $expense = $this->service->register($this->gastoConFactura());

        $this->assertSame($existente->id, $expense->fiscalDocument->supplier_id);
        $this->assertSame(1, Supplier::where('rtn', '08019970000001')->count());
    }

    public function test_restaura_un_proveedor_eliminado_con_el_mismo_rtn(): void
    {
        $eliminado = Supplier::factory()->create(['rtn' => '08019970000001']);
        $eliminado->delete();

        $expense = $this->service->register($this->gastoConFactura());

        $this->assertSame($eliminado->id, $expense->fiscalDocument->supplier_id);
        $this->assertFalse($eliminado->fresh()->trashed());
    }

    // ─── Protecciones: el gasto se revierte completo ─────────

    public function test_factura_ya_registrada_en_compras_revierte_el_gasto(): void
    {
        $supplier = Supplier::factory()->create(['rtn' => '08019970000001']);
        Purchase::factory()->fromSupplier($supplier)->confirmada()->withTotals(taxable: 1000)->create([
            'supplier_invoice_number' => '001-001-01-00012345',
            'date' => '2026-09-17',
        ]);

        $this->expectException(FacturaYaRegistradaException::class);

        try {
            $this->service->register($this->gastoConFactura());
        } finally {
            $this->assertSame(0, Expense::count(), 'El gasto no debe quedar registrado sin su copia fiscal.');
        }
    }

    public function test_periodo_declarado_revierte_el_gasto(): void
    {
        FiscalPeriod::factory()->forMonth(2026, 8)->declared(User::factory()->create())->create();

        $this->expectException(PeriodoFiscalCerradoException::class);

        try {
            $this->service->register($this->gastoConFactura(['provider_invoice_date' => '2026-08-30']));
        } finally {
            $this->assertSame(0, Expense::count());
            $this->assertSame(0, Purchase::count());
        }
    }

    public function test_gravado_mas_isv_mayor_que_el_total_se_rechaza_en_el_campo_gravado(): void
    {
        try {
            $this->service->register($this->gastoConFactura(['amount_total' => 1000.00]));
            $this->fail('Debió rechazar gravado + ISV > total.');
        } catch (MontosDocumentoInvalidosException $e) {
            $this->assertSame('taxable_amount', $e->field);
        }

        $this->assertSame(0, Expense::count());
    }

    // ─── Editar ──────────────────────────────────────────────

    public function test_editar_los_datos_fiscales_actualiza_la_misma_compra(): void
    {
        $expense = $this->service->register($this->gastoConFactura());
        $purchaseId = $expense->fiscalDocument->id;

        $this->service->updateFiscalData($expense, [
            'taxable_amount' => 900.00,
            'isv_amount' => 135.00,
            'provider_invoice_number' => '001-001-01-00012399',
        ]);

        $purchase = Purchase::findOrFail($purchaseId);
        $this->assertSame('001-001-01-00012399', $purchase->supplier_invoice_number);
        $this->assertEquals(900.00, (float) $purchase->taxable_total);
        $this->assertEquals(135.00, (float) $purchase->isv);
        $this->assertEquals(115.00, (float) $purchase->exempt_total, '1150 − 900 − 135.');
        $this->assertSame(1, Purchase::count());
    }

    public function test_editar_no_toca_los_campos_estructurales_del_gasto(): void
    {
        $expense = $this->service->register($this->gastoConFactura());

        $this->service->updateFiscalData($expense, ['amount_total' => 1.00, 'description' => 'Corregida']);

        $this->assertEquals(1150.00, (float) $expense->fresh()->amount_total);
        $this->assertSame('Corregida', $expense->fresh()->description);
    }

    public function test_desmarcar_la_factura_anula_la_compra_y_volver_a_marcarla_crea_otra(): void
    {
        $expense = $this->service->register($this->gastoConFactura());
        $primera = $expense->fiscalDocument;

        $this->service->updateFiscalData($expense, ['is_isv_deductible' => false]);

        $this->assertSame(PurchaseStatus::Anulada, $primera->fresh()->status, 'Sale del Libro de Compras.');
        $this->assertNull($expense->fresh()->fiscalDocument);

        $this->service->updateFiscalData($expense->fresh(), ['is_isv_deductible' => true]);

        $segunda = $expense->fresh()->fiscalDocument;
        $this->assertNotNull($segunda);
        $this->assertNotSame($primera->id, $segunda->id);
        $this->assertSame(2, $expense->purchases()->count(), 'La anulada queda como rastro.');
    }

    // ─── Gastos heredados (deducibles antes de la Fase 1b) ───

    public function test_gasto_heredado_sin_gravado_no_se_copia_al_editarlo(): void
    {
        $heredado = Expense::factory()->withProvider('08019970000001')->create([
            'establishment_id' => $this->matriz->id,
            'expense_date' => '2026-09-10',
            'amount_total' => 1150.00,
            'taxable_amount' => null,
        ]);

        $this->service->updateFiscalData($heredado, ['description' => 'Solo cambio la descripción']);

        $this->assertSame(0, Purchase::count(), 'Decisión 2026-09-28: solo de aquí en adelante.');
    }

    public function test_gasto_heredado_al_cargarle_el_gravado_pasa_al_libro(): void
    {
        $heredado = Expense::factory()->withProvider('08019970000001')->create([
            'establishment_id' => $this->matriz->id,
            'expense_date' => '2026-09-10',
            'amount_total' => 1150.00,
            'taxable_amount' => null,
        ]);

        $this->service->updateFiscalData($heredado, ['taxable_amount' => 1000.00, 'isv_amount' => 150.00]);

        $this->assertNotNull($heredado->fresh()->fiscalDocument);
    }
}

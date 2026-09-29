<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\CashMovementType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseKind;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Migración de datos 2026_09_28_140002: el módulo Gastos pasa a Compras.
 *
 * Es la operación más delicada del rediseño "todo en Compras": corre una sola
 * vez sobre los datos reales de producción. Estos tests fijan su contrato:
 *   - Cada gasto → compra tipo Gasto, Recibo Interno, confirmada y pagada,
 *     con el monto completo (la Utilidad Neta histórica no cambia).
 *   - Nunca entra al Libro de Compras (los meses declarados quedan igual);
 *     los datos de la factura quedan en las notas.
 *   - Su salida de caja se re-enlaza a la compra (anularla la devuelve).
 *   - Las comisiones de tarjeta pasan a la venta, no a Compras.
 *   - down() lo deja todo como estaba.
 */
class MoveExpensesToPurchasesMigrationTest extends TestCase
{
    use CreatesMatriz, RefreshDatabase;

    private const MIGRATION = '2026_09_28_140002_move_expenses_to_purchases.php';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function runUp(): string
    {
        ob_start();
        $this->migration()->up();

        return (string) ob_get_clean();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertExpense(array $overrides = []): int
    {
        return DB::table('expenses')->insertGetId([
            'establishment_id' => $this->matriz->id,
            'user_id' => $this->user->id,
            'expense_date' => '2026-04-15',
            'category' => ExpenseCategory::Combustible->value,
            'payment_method' => PaymentMethod::Efectivo->value,
            'amount_total' => 230.00,
            'isv_amount' => null,
            'is_isv_deductible' => false,
            'description' => 'Gasolina moto',
            'created_by' => $this->user->id,
            'created_at' => '2026-04-15 15:00:00',
            'updated_at' => '2026-04-15 15:00:00',
            ...$overrides,
        ]);
    }

    private function purchaseFor(int $expenseId): Purchase
    {
        return Purchase::query()->where('legacy_expense_id', $expenseId)->sole();
    }

    // ─── Gasto → compra ──────────────────────────────────────

    public function test_gasto_se_convierte_en_compra_tipo_gasto_recibo_interno_confirmada(): void
    {
        $expenseId = $this->insertExpense();

        $output = $this->runUp();

        $purchase = $this->purchaseFor($expenseId);
        $this->assertSame(PurchaseKind::Gasto, $purchase->kind);
        $this->assertSame(ExpenseCategory::Combustible, $purchase->expense_category);
        $this->assertSame('Gasolina moto', $purchase->description);
        $this->assertSame(PaymentMethod::Efectivo, $purchase->payment_method);
        $this->assertSame(SupplierDocumentType::ReciboInterno, $purchase->document_type);
        $this->assertSame(PurchaseStatus::Confirmada, $purchase->status);
        $this->assertSame(PaymentStatus::Pagada, $purchase->payment_status);
        $this->assertSame('2026-04-15', $purchase->date->toDateString());
        $this->assertSame($this->matriz->id, $purchase->establishment_id);
        $this->assertSame($this->user->id, $purchase->created_by);
        $this->assertSame(Supplier::forInternalReceipts()->id, $purchase->supplier_id);
        $this->assertSame('RI-20260415-0001', $purchase->supplier_invoice_number);
        $this->assertMatchesRegularExpression('/^COMP-\d{4}-\d{5}$/', $purchase->purchase_number);

        // Monto completo como subtotal: la Utilidad Neta histórica no cambia.
        $this->assertEquals(230.00, (float) $purchase->subtotal);
        $this->assertEquals(230.00, (float) $purchase->exempt_total);
        $this->assertEquals(0.00, (float) $purchase->isv);
        $this->assertEquals(230.00, (float) $purchase->total);

        $this->assertStringContainsString('Gastos convertidos en compras tipo gasto: 1', $output);
    }

    public function test_gasto_con_factura_conserva_sus_datos_en_notas_sin_entrar_al_libro(): void
    {
        $proveedor = Supplier::factory()->create(['rtn' => '08019970000001', 'name' => 'Gasolinera UNO']);

        $expenseId = $this->insertExpense([
            'amount_total' => 115.00,
            'isv_amount' => 15.00,
            'is_isv_deductible' => true,
            'provider_name' => 'Gasolinera UNO',
            'provider_rtn' => '08019970000001',
            'provider_invoice_number' => '001-001-01-00012345',
            'provider_invoice_cai' => 'ABCDEF-123456-789ABC-DEF012-345678-AB',
        ]);

        $this->runUp();

        $purchase = $this->purchaseFor($expenseId);
        $this->assertSame($proveedor->id, $purchase->supplier_id, 'Proveedor registrado con ese RTN.');
        $this->assertSame(SupplierDocumentType::ReciboInterno, $purchase->document_type,
            'Recibo Interno a propósito: el Libro de Compras de meses ya declarados no cambia.');
        $this->assertEquals(115.00, (float) $purchase->subtotal);
        $this->assertStringContainsString('001-001-01-00012345', $purchase->notes);
        $this->assertStringContainsString('ABCDEF-123456-789ABC-DEF012-345678-AB', $purchase->notes);
        $this->assertStringContainsString('ISV desglosado: L 15.00', $purchase->notes);
    }

    public function test_rtn_desconocido_cae_al_proveedor_generico_con_el_nombre_en_notas(): void
    {
        $expenseId = $this->insertExpense([
            'provider_name' => 'Taller Don Chepe',
            'provider_rtn' => '08019999999911',
        ]);

        $this->runUp();

        $purchase = $this->purchaseFor($expenseId);
        $this->assertSame(Supplier::forInternalReceipts()->id, $purchase->supplier_id);
        $this->assertStringContainsString('Taller Don Chepe', $purchase->notes);
        $this->assertStringContainsString('08019999999911', $purchase->notes);
    }

    public function test_el_correlativo_ri_continua_la_secuencia_del_dia(): void
    {
        Purchase::factory()->create([
            'document_type' => SupplierDocumentType::ReciboInterno,
            'supplier_invoice_number' => 'RI-20260415-0002',
            'supplier_cai' => null,
            'date' => '2026-04-15',
        ]);
        $primero = $this->insertExpense();
        $segundo = $this->insertExpense(['description' => 'Otro gasto']);

        $this->runUp();

        $this->assertSame('RI-20260415-0003', $this->purchaseFor($primero)->supplier_invoice_number);
        $this->assertSame('RI-20260415-0004', $this->purchaseFor($segundo)->supplier_invoice_number);
    }

    // ─── Caja ────────────────────────────────────────────────

    public function test_la_salida_de_caja_del_gasto_se_enlaza_a_la_compra(): void
    {
        $expenseId = $this->insertExpense();
        $session = CashSession::factory()->forEstablishment($this->matriz)->openedBy($this->user)->create();
        $movement = CashMovement::factory()->create([
            'cash_session_id' => $session->id,
            'user_id' => $this->user->id,
            'type' => CashMovementType::Expense,
            'payment_method' => PaymentMethod::Efectivo,
            'amount' => 230.00,
        ]);
        DB::table('cash_movements')->where('id', $movement->id)->update(['expense_id' => $expenseId]);

        $this->runUp();

        $purchase = $this->purchaseFor($expenseId);
        $this->assertSame([$movement->id], $purchase->cashMovements()->pluck('id')->all());
        $this->assertSame(1, CashMovement::count(), 'No se crean movimientos nuevos: el dinero ya salió.');
    }

    // ─── Comisiones de tarjeta ───────────────────────────────

    public function test_comisiones_de_tarjeta_pasan_a_la_venta_y_no_a_compras(): void
    {
        $sale = Sale::factory()->create(['date' => '2026-04-15', 'total' => 1000.00]);
        $this->insertExpense([
            'category' => ExpenseCategory::ComisionesBancarias->value,
            'payment_method' => PaymentMethod::TarjetaCredito->value,
            'amount_total' => 34.00,
            'description' => 'Comisión tarjeta',
            'sale_id' => $sale->id,
        ]);

        $output = $this->runUp();

        $this->assertEquals(34.00, (float) $sale->fresh()->card_fee_amount);
        $this->assertSame(0, Purchase::query()->gastos()->count());
        $this->assertStringContainsString('Comisiones de tarjeta pasadas a sus ventas: 1', $output);
    }

    public function test_comision_bancaria_manual_sin_venta_si_se_vuelve_compra(): void
    {
        // Cargo bancario registrado a mano (sin venta): es un gasto normal.
        $expenseId = $this->insertExpense([
            'category' => ExpenseCategory::ComisionesBancarias->value,
            'payment_method' => PaymentMethod::Transferencia->value,
            'amount_total' => 75.00,
            'description' => 'Cargo por manejo de cuenta',
        ]);

        $this->runUp();

        $this->assertSame(ExpenseCategory::ComisionesBancarias, $this->purchaseFor($expenseId)->expense_category);
    }

    // ─── Reversa ─────────────────────────────────────────────

    public function test_down_revierte_compras_enlaces_de_caja_y_comisiones(): void
    {
        $expenseId = $this->insertExpense();
        $session = CashSession::factory()->forEstablishment($this->matriz)->openedBy($this->user)->create();
        $movement = CashMovement::factory()->create([
            'cash_session_id' => $session->id,
            'user_id' => $this->user->id,
            'type' => CashMovementType::Expense,
            'amount' => 230.00,
        ]);
        DB::table('cash_movements')->where('id', $movement->id)->update(['expense_id' => $expenseId]);

        $conComision = Sale::factory()->create(['date' => '2026-04-15']);
        $this->insertExpense([
            'category' => ExpenseCategory::ComisionesBancarias->value,
            'amount_total' => 34.00,
            'sale_id' => $conComision->id,
        ]);
        $compraNormal = Purchase::factory()->create();

        $this->runUp();
        $this->migration()->down();

        $this->assertSame(0, Purchase::query()->whereNotNull('legacy_expense_id')->count());
        $this->assertNotNull($compraNormal->fresh(), 'Las compras que no vinieron de un gasto no se tocan.');
        $this->assertNull($movement->fresh()->reference_id);
        $this->assertEquals(0.00, (float) $conComision->fresh()->card_fee_amount);
        $this->assertSame(2, DB::table('expenses')->count(), 'La tabla expenses queda intacta como respaldo.');
    }
}

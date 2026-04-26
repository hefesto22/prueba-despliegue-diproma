<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Expenses;

use App\Enums\CashMovementType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Exceptions\Cash\NoHayCajaAbiertaException;
use App\Models\CashMovement;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\Expense;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\Expenses\ExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cubre la orquestación entre Expense (registro contable) y CashMovement
 * (kardex de caja). Reglas críticas:
 *
 *   - Gasto en Efectivo: crea Expense + CashMovement vinculado (expense_id).
 *     Atómico — si falla cualquiera, ambos hacen rollback.
 *   - Gasto en Tarjeta/Transferencia/Cheque: crea SOLO el Expense (no toca caja).
 *   - Gasto en Efectivo sin caja abierta en la sucursal: NoHayCajaAbiertaException
 *     y rollback total (no queda Expense huérfano).
 *   - El CashMovement se crea con expense_id ya seteado (no requiere UPDATE
 *     posterior) — garantía estructural contra Expenses huérfanos.
 */
class ExpenseServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseService $service;

    private CashSessionService $cashSessions;

    private CompanySetting $company;

    private Establishment $matriz;

    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');
        $this->company = CompanySetting::factory()->create([
            'rtn' => '08011999123456',
            'cash_discrepancy_tolerance' => 50.00,
        ]);
        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        $this->cajero = User::factory()->create();

        $this->cashSessions = app(CashSessionService::class);
        $this->service = app(ExpenseService::class);
    }

    // ─── Gasto en efectivo: vincula CashMovement ──────────────

    public function test_gasto_efectivo_crea_expense_y_cash_movement_vinculados(): void
    {
        $session = $this->cashSessions->open($this->matriz->id, $this->cajero, 1000.00);

        $expense = $this->service->register([
            'establishment_id'  => $this->matriz->id,
            'user_id'           => $this->cajero->id,
            'expense_date'      => now()->toDateString(),
            'category'          => ExpenseCategory::Combustible->value,
            'payment_method'    => PaymentMethod::Efectivo->value,
            'amount_total'      => 250.00,
            'description'       => 'Gasolina mensajería',
            'is_isv_deductible' => false,
        ]);

        // Expense persistió.
        $this->assertNotNull($expense->id);
        $this->assertSame('250.00', $expense->amount_total);
        $this->assertSame(PaymentMethod::Efectivo, $expense->payment_method);

        // CashMovement se creó vinculado vía expense_id, en la sesión abierta.
        $movement = $expense->cashMovement;
        $this->assertNotNull($movement);
        $this->assertSame($expense->id, $movement->expense_id);
        $this->assertSame($session->id, $movement->cash_session_id);
        $this->assertSame(CashMovementType::Expense, $movement->type);
        $this->assertSame('250.00', $movement->amount);
        $this->assertSame(ExpenseCategory::Combustible, $movement->category);
    }

    // ─── Gasto NO efectivo: NO crea CashMovement ──────────────

    public function test_gasto_tarjeta_solo_crea_expense_sin_cash_movement(): void
    {
        // Hay caja abierta — pero como el gasto es con tarjeta, no debe tocarla.
        $this->cashSessions->open($this->matriz->id, $this->cajero, 1000.00);

        $expense = $this->service->register([
            'establishment_id'  => $this->matriz->id,
            'user_id'           => $this->cajero->id,
            'expense_date'      => now()->toDateString(),
            'category'          => ExpenseCategory::Servicios->value,
            'payment_method'    => PaymentMethod::TarjetaCredito->value,
            'amount_total'      => 1500.00,
            'description'       => 'Hosting mensual',
            'is_isv_deductible' => true,
        ]);

        $this->assertNotNull($expense->id);
        $this->assertNull($expense->cashMovement);

        $this->assertDatabaseMissing('cash_movements', [
            'expense_id' => $expense->id,
        ]);
    }

    public function test_gasto_transferencia_solo_crea_expense_sin_cash_movement(): void
    {
        $this->cashSessions->open($this->matriz->id, $this->cajero, 1000.00);

        $expense = $this->service->register([
            'establishment_id'  => $this->matriz->id,
            'user_id'           => $this->cajero->id,
            'expense_date'      => now()->toDateString(),
            'category'          => ExpenseCategory::Servicios->value,
            'payment_method'    => PaymentMethod::Transferencia->value,
            'amount_total'      => 800.00,
            'description'       => 'Pago a proveedor',
            'is_isv_deductible' => false,
        ]);

        $this->assertNull($expense->cashMovement);
    }

    // ─── Sin caja abierta: rollback total ─────────────────────

    public function test_gasto_efectivo_sin_caja_abierta_lanza_excepcion_y_no_persiste_expense(): void
    {
        // No hay sesión abierta en la matriz.

        try {
            $this->service->register([
                'establishment_id'  => $this->matriz->id,
                'user_id'           => $this->cajero->id,
                'expense_date'      => now()->toDateString(),
                'category'          => ExpenseCategory::Otros->value,
                'payment_method'    => PaymentMethod::Efectivo->value,
                'amount_total'      => 100.00,
                'description'       => 'Sin caja abierta',
                'is_isv_deductible' => false,
            ]);

            $this->fail('Esperaba NoHayCajaAbiertaException');
        } catch (NoHayCajaAbiertaException) {
            // esperado
        }

        // Atomicidad: el Expense NO debe haber quedado en BD aunque se creó
        // antes del CashMovement. La transacción del service debe revertir todo.
        $this->assertSame(0, Expense::query()->count());
        $this->assertSame(0, CashMovement::query()
            ->where('type', CashMovementType::Expense->value)
            ->count());
    }

    // ─── Gasto efectivo no afecta caja de OTRA sucursal ───────

    public function test_gasto_efectivo_falla_si_la_sucursal_del_gasto_no_tiene_caja_abierta(): void
    {
        // Caja abierta en la matriz, gasto a registrar en otra sucursal sin caja.
        $this->cashSessions->open($this->matriz->id, $this->cajero, 1000.00);

        $sucursalB = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create(['is_main' => false]);

        $this->expectException(NoHayCajaAbiertaException::class);

        $this->service->register([
            'establishment_id'  => $sucursalB->id,
            'user_id'           => $this->cajero->id,
            'expense_date'      => now()->toDateString(),
            'category'          => ExpenseCategory::Otros->value,
            'payment_method'    => PaymentMethod::Efectivo->value,
            'amount_total'      => 100.00,
            'description'       => 'Sucursal sin caja',
            'is_isv_deductible' => false,
        ]);
    }

    // ─── Cálculo de expected post-gasto ───────────────────────

    public function test_gasto_efectivo_descuenta_expected_de_la_sesion_al_cierre(): void
    {
        $session = $this->cashSessions->open($this->matriz->id, $this->cajero, 1000.00);

        // Gasto en efectivo de L. 200 → expected al cierre: 1000 - 200 = 800.
        $this->service->register([
            'establishment_id'  => $this->matriz->id,
            'user_id'           => $this->cajero->id,
            'expense_date'      => now()->toDateString(),
            'category'          => ExpenseCategory::Combustible->value,
            'payment_method'    => PaymentMethod::Efectivo->value,
            'amount_total'      => 200.00,
            'description'       => 'Combustible',
            'is_isv_deductible' => false,
        ]);

        $closed = $this->cashSessions->close($session->fresh(), $this->cajero, 800.00);
        $this->assertSame('800.00', $closed->expected_closing_amount);
        $this->assertSame('0.00', $closed->discrepancy);
    }

    // ─── Atomicidad transaccional ─────────────────────────────

    public function test_register_corre_dentro_de_transaccion_y_revierte_ante_falla_externa(): void
    {
        $session = $this->cashSessions->open($this->matriz->id, $this->cajero, 1000.00);

        // Envolvemos la llamada a register() en una transacción externa que
        // aborta. El Expense + CashMovement creados internamente deben revertirse
        // junto con la transacción del caller (DB::transaction soporta savepoints).
        try {
            DB::transaction(function () {
                $this->service->register([
                    'establishment_id'  => $this->matriz->id,
                    'user_id'           => $this->cajero->id,
                    'expense_date'      => now()->toDateString(),
                    'category'          => ExpenseCategory::Otros->value,
                    'payment_method'    => PaymentMethod::Efectivo->value,
                    'amount_total'      => 50.00,
                    'description'       => 'Va a abortar',
                    'is_isv_deductible' => false,
                ]);

                throw new \RuntimeException('Abort externo intencional');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertDatabaseMissing('expenses', ['amount_total' => 50.00]);
        $this->assertDatabaseMissing('cash_movements', [
            'cash_session_id' => $session->id,
            'amount' => 50.00,
        ]);
    }
}

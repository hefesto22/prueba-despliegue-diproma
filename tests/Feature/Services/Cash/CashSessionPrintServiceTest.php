<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Cash;

use App\Enums\CashMovementType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\User;
use App\Services\Cash\CashSessionPrintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Garantiza que el payload para la Blade de impresión de cierre sea coherente.
 *
 * La Blade es puramente declarativa: si el payload está mal, la Blade muestra
 * basura o rompe. Estos tests bloquean regresiones en el contrato del payload.
 *
 * No testean la Blade ni window.print() — eso ocurre en el navegador del
 * usuario y está fuera del alcance de PHPUnit.
 */
class CashSessionPrintServiceTest extends TestCase
{
    use RefreshDatabase, CreatesMatriz;

    private CashSessionPrintService $service;

    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = User::factory()->create(['name' => 'Cajero Uno']);
        $this->service = app(CashSessionPrintService::class);
    }

    private function makeOpenSession(float $opening = 1000.00): CashSession
    {
        return CashSession::factory()
            ->forEstablishment($this->matriz)
            ->openedBy($this->cajero)
            ->openingAmount($opening)
            ->create();
    }

    private function makeClosedSession(
        float $opening = 1000.00,
        float $expected = 1000.00,
        float $actual = 1000.00,
        ?User $closedBy = null,
        ?User $authorizedBy = null,
    ): CashSession {
        $closer = $closedBy ?? $this->cajero;

        return CashSession::factory()
            ->forEstablishment($this->matriz)
            ->openedBy($this->cajero)
            ->openingAmount($opening)
            ->create([
                'closed_at' => now(),
                'closed_by_user_id' => $closer->id,
                'expected_closing_amount' => $expected,
                'actual_closing_amount' => $actual,
                'discrepancy' => round($actual - $expected, 2),
                'authorized_by_user_id' => $authorizedBy?->id,
            ]);
    }

    // ─── Shape general del payload ───────────────────────────

    public function test_payload_contiene_todas_las_claves_principales(): void
    {
        $session = $this->makeOpenSession();

        $payload = $this->service->buildPrintPayload($session);

        foreach ([
            'session', 'isOpen', 'company', 'establishment', 'people', 'dates',
            'balances', 'cashFlow', 'byPaymentMethod', 'byExpenseCategory',
            'movements', 'meta',
        ] as $key) {
            $this->assertArrayHasKey($key, $payload, "Falta clave '{$key}' en payload");
        }
    }

    // ─── isOpen flag ─────────────────────────────────────────

    public function test_is_open_true_para_sesion_abierta(): void
    {
        $payload = $this->service->buildPrintPayload($this->makeOpenSession());

        $this->assertTrue($payload['isOpen']);
    }

    public function test_is_open_false_para_sesion_cerrada(): void
    {
        $payload = $this->service->buildPrintPayload($this->makeClosedSession());

        $this->assertFalse($payload['isOpen']);
    }

    // ─── Company / Establishment ─────────────────────────────

    public function test_company_block_lee_del_establishment(): void
    {
        $session = $this->makeOpenSession();

        $payload = $this->service->buildPrintPayload($session);

        $expectedName = (string) ($this->matrizCompany->trade_name ?: $this->matrizCompany->legal_name);
        $this->assertSame($expectedName, $payload['company']['name']);
        $this->assertNotEmpty($payload['company']['rtn']);
    }

    public function test_establishment_block_contiene_nombre_y_codigo(): void
    {
        $session = $this->makeOpenSession();

        $payload = $this->service->buildPrintPayload($session);

        $this->assertSame($this->matriz->name, $payload['establishment']['name']);
        $this->assertSame($this->matriz->code, $payload['establishment']['code']);
        $this->assertTrue($payload['establishment']['is_main']);
    }

    // ─── People ──────────────────────────────────────────────

    public function test_people_incluye_opened_by_siempre(): void
    {
        $payload = $this->service->buildPrintPayload($this->makeOpenSession());

        $this->assertSame('Cajero Uno', $payload['people']['opened_by']);
        $this->assertNull($payload['people']['closed_by']);
        $this->assertNull($payload['people']['authorized_by']);
    }

    public function test_people_incluye_closed_y_authorized_cuando_existen(): void
    {
        $gerente = User::factory()->create(['name' => 'Gerente de Turno']);
        $session = $this->makeClosedSession(
            opening: 1000.00,
            expected: 1000.00,
            actual: 800.00,
            authorizedBy: $gerente,
        );

        $payload = $this->service->buildPrintPayload($session);

        $this->assertSame('Cajero Uno', $payload['people']['closed_by']);
        $this->assertSame('Gerente de Turno', $payload['people']['authorized_by']);
    }

    // ─── Balances y signos de descuadre ──────────────────────

    public function test_balances_montos_formateados_con_dos_decimales(): void
    {
        $payload = $this->service->buildPrintPayload(
            $this->makeClosedSession(1000.00, 1200.00, 1150.50)
        );

        $this->assertSame('1,000.00', $payload['balances']['opening']);
        $this->assertSame('1,200.00', $payload['balances']['expected']);
        $this->assertSame('1,150.50', $payload['balances']['actual']);
        $this->assertSame('-49.50', $payload['balances']['discrepancy']);
    }

    public function test_discrepancy_sign_exact_cuando_cuadra(): void
    {
        $payload = $this->service->buildPrintPayload(
            $this->makeClosedSession(1000.00, 1000.00, 1000.00)
        );

        $this->assertSame('exact', $payload['balances']['discrepancy_sign']);
    }

    public function test_discrepancy_sign_positive_cuando_sobra(): void
    {
        $payload = $this->service->buildPrintPayload(
            $this->makeClosedSession(1000.00, 1000.00, 1050.00)
        );

        $this->assertSame('positive', $payload['balances']['discrepancy_sign']);
    }

    public function test_discrepancy_sign_negative_cuando_falta(): void
    {
        $payload = $this->service->buildPrintPayload(
            $this->makeClosedSession(1000.00, 1000.00, 900.00)
        );

        $this->assertSame('negative', $payload['balances']['discrepancy_sign']);
    }

    public function test_discrepancy_sign_pending_para_sesion_abierta(): void
    {
        $payload = $this->service->buildPrintPayload($this->makeOpenSession());

        $this->assertSame('pending', $payload['balances']['discrepancy_sign']);
        $this->assertNull($payload['balances']['discrepancy']);
        $this->assertNull($payload['balances']['actual']);
    }

    // ─── Agregados por método y categoría ───────────────────

    public function test_by_payment_method_ordena_por_monto_descendente(): void
    {
        $session = $this->makeOpenSession();

        CashMovement::factory()->forSession($session)->saleIncome(300.00, PaymentMethod::Efectivo)->create();
        CashMovement::factory()->forSession($session)->saleIncome(800.00, PaymentMethod::TarjetaCredito)->create();
        CashMovement::factory()->forSession($session)->saleIncome(500.00, PaymentMethod::Transferencia)->create();

        $rows = $this->service->buildPrintPayload($session->fresh())['byPaymentMethod'];

        $this->assertCount(3, $rows);
        $this->assertSame(PaymentMethod::TarjetaCredito->value, $rows[0]['method']);
        $this->assertSame('800.00', $rows[0]['amount']);
        $this->assertSame(PaymentMethod::Transferencia->value, $rows[1]['method']);
        $this->assertSame(PaymentMethod::Efectivo->value, $rows[2]['method']);
    }

    public function test_by_expense_category_ordena_por_monto_descendente(): void
    {
        $session = $this->makeOpenSession();

        CashMovement::factory()->forSession($session)->expense(50.00, ExpenseCategory::Papeleria)->create();
        CashMovement::factory()->forSession($session)->expense(200.00, ExpenseCategory::Combustible)->create();
        CashMovement::factory()->forSession($session)->expense(120.00, ExpenseCategory::Mensajeria)->create();

        $rows = $this->service->buildPrintPayload($session->fresh())['byExpenseCategory'];

        $this->assertCount(3, $rows);
        $this->assertSame(ExpenseCategory::Combustible->value, $rows[0]['category']);
        $this->assertSame('Combustible', $rows[0]['label']);
        $this->assertSame(ExpenseCategory::Mensajeria->value, $rows[1]['category']);
        $this->assertSame(ExpenseCategory::Papeleria->value, $rows[2]['category']);
    }

    public function test_by_expense_category_vacio_sin_gastos(): void
    {
        $payload = $this->service->buildPrintPayload($this->makeOpenSession());

        $this->assertSame([], $payload['byExpenseCategory']);
    }

    // ─── Movimientos (kardex) ────────────────────────────────

    public function test_movements_incluyen_flags_de_direccion(): void
    {
        $session = $this->makeOpenSession();

        CashMovement::factory()->forSession($session)->saleIncome(100.00, PaymentMethod::Efectivo)->create();
        CashMovement::factory()->forSession($session)->expense(50.00, ExpenseCategory::Otros)->create();

        $movements = $this->service->buildPrintPayload($session->fresh())['movements'];

        $this->assertCount(2, $movements);

        $inflow = collect($movements)->firstWhere('type', CashMovementType::SaleIncome->value);
        $this->assertTrue($inflow['is_inflow']);
        $this->assertFalse($inflow['is_outflow']);

        $outflow = collect($movements)->firstWhere('type', CashMovementType::Expense->value);
        $this->assertFalse($outflow['is_inflow']);
        $this->assertTrue($outflow['is_outflow']);
    }

    public function test_movements_vacio_sin_movimientos(): void
    {
        $payload = $this->service->buildPrintPayload($this->makeOpenSession());

        $this->assertSame([], $payload['movements']);
    }

    // ─── Meta ────────────────────────────────────────────────

    public function test_meta_incluye_printed_at_y_printed_by(): void
    {
        $this->actingAs($this->cajero);

        $payload = $this->service->buildPrintPayload($this->makeOpenSession());

        $this->assertArrayHasKey('printed_at', $payload['meta']);
        $this->assertSame('Cajero Uno', $payload['meta']['printed_by']);
    }

    public function test_meta_printed_by_cae_a_guion_sin_auth(): void
    {
        $payload = $this->service->buildPrintPayload($this->makeOpenSession());

        $this->assertSame('—', $payload['meta']['printed_by']);
    }
}

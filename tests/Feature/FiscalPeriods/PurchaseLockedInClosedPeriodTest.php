<?php

namespace Tests\Feature\FiscalPeriods;

use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\FiscalPeriod;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalCerradoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cubre el PurchaseObserver (ISV.3a): la inmutabilidad fiscal de las compras
 * cuyo período ya fue declarado al SAR.
 *
 * Reglas bajo test:
 *   - creating: compras retroactivas a período declarado → bloqueo.
 *   - updating: compras cuyo período original está declarado → bloqueo
 *               (independiente de la fecha propuesta).
 *   - updating: mover la fecha hacia un período declarado → bloqueo.
 *   - deleting: borrar compras de período declarado → bloqueo.
 *   - Default allow: si fiscal_period_start es NULL → no bloquea nada.
 *   - Default allow: si el período está reabierto (rectificativa) → permite.
 *
 * Setup idéntico al de FiscalPeriodServiceTest: tracking desde 2026-01-01,
 * matriz preexistente para evitar el chain de Establishment::factory() que
 * crearía un CompanySetting duplicado.
 */
class PurchaseLockedInClosedPeriodTest extends TestCase
{
    use RefreshDatabase;

    private CompanySetting $company;

    private Establishment $matriz;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');

        $this->company = CompanySetting::factory()->create([
            'fiscal_period_start' => '2026-01-01',
        ]);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        $this->refreshCompanyCache();

        // Proveedor único reusado en todas las compras del test. Evita que cada
        // Purchase::factory() cree un Supplier (costoso e irrelevante al test).
        $this->supplier = Supplier::factory()->create();
    }

    private function refreshCompanyCache(): void
    {
        Cache::put('company_settings', $this->company->fresh(), 60 * 60 * 24);
    }

    private function makePurchase(array $attrs = []): Purchase
    {
        return Purchase::factory()->create(array_merge([
            'establishment_id' => $this->matriz->id,
            'supplier_id' => $this->supplier->id,
        ], $attrs));
    }

    private function closePeriod(int $year, int $month): FiscalPeriod
    {
        $user = User::factory()->create();

        return FiscalPeriod::factory()
            ->forMonth($year, $month)
            ->declared($user)
            ->create();
    }

    // ─── creating ────────────────────────────────────────────────────

    public function test_creating_purchase_in_open_period_succeeds(): void
    {
        // Sin período declarado: el creating debe pasar sin bloqueo.
        $purchase = $this->makePurchase(['date' => '2026-03-10']);

        $this->assertNotNull($purchase->id);
    }

    public function test_creating_purchase_in_declared_period_is_blocked(): void
    {
        $this->closePeriod(2026, 3);

        try {
            $this->makePurchase(['date' => '2026-03-15']);
            $this->fail('Se esperaba PeriodoFiscalCerradoException al crear compra en período declarado.');
        } catch (PeriodoFiscalCerradoException $e) {
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(3, $e->periodMonth);
        }

        $this->assertEquals(0, Purchase::count(), 'La compra NO debe haberse persistido.');
    }

    public function test_creating_purchase_with_pre_tracking_date_is_blocked(): void
    {
        // fiscal_period_start = 2026-01-01; fecha previa → pre-tracking = cerrado.
        $this->expectException(PeriodoFiscalCerradoException::class);

        $this->makePurchase(['date' => '2025-12-20']);
    }

    public function test_creating_purchase_in_reopened_period_is_allowed(): void
    {
        // Un período reabierto para rectificativa vuelve a estar "abierto".
        $user = User::factory()->create();
        FiscalPeriod::factory()
            ->forMonth(2026, 3)
            ->reopened($user, 'Rectificativa por error en Libro de Compras')
            ->create();

        $purchase = $this->makePurchase(['date' => '2026-03-15']);

        $this->assertNotNull($purchase->id);
    }

    public function test_creating_purchase_does_nothing_when_module_not_configured(): void
    {
        // Default allow: si no hay fiscal_period_start, el Observer no bloquea
        // aunque haya un FiscalPeriod declarado (no debería haberlo, pero
        // robustez ante datos huérfanos).
        $this->company->update(['fiscal_period_start' => null]);
        $this->refreshCompanyCache();

        $purchase = $this->makePurchase(['date' => '2026-03-15']);

        $this->assertNotNull($purchase->id);
    }

    // ─── updating ────────────────────────────────────────────────────

    public function test_updating_purchase_in_open_period_succeeds(): void
    {
        $purchase = $this->makePurchase(['date' => '2026-03-10']);

        $purchase->update(['notes' => 'Nueva nota']);

        $this->assertEquals('Nueva nota', $purchase->fresh()->notes);
    }

    public function test_updating_purchase_in_declared_period_is_blocked(): void
    {
        // Creamos la compra ANTES de declarar el período (flujo normal).
        // `notes => null` explícito: el default de la factory randomiza el campo
        // en 30% de las ejecuciones (fake()->optional(0.3)->sentence()), lo que
        // vuelve flaky la aserción final `assertNull($purchase->fresh()->notes)`
        // cuando el seed del Faker se desplaza al agregar tests nuevos.
        $purchase = $this->makePurchase(['date' => '2026-03-10', 'notes' => null]);

        // Ahora declaramos marzo 2026.
        $this->closePeriod(2026, 3);

        try {
            $purchase->update(['notes' => 'Intento post-declaración']);
            $this->fail('Se esperaba PeriodoFiscalCerradoException al editar compra en período declarado.');
        } catch (PeriodoFiscalCerradoException $e) {
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(3, $e->periodMonth);
        }

        // La compra no debe tener la nueva nota persistida.
        $this->assertNull($purchase->fresh()->notes);
    }

    public function test_updating_purchase_cannot_move_date_to_declared_period(): void
    {
        // Escenario de evasión: un usuario crea una compra en período abierto (marzo)
        // y luego intenta "mover" su fecha a un período ya declarado (febrero).
        $this->closePeriod(2026, 2);
        $purchase = $this->makePurchase(['date' => '2026-03-10']);

        try {
            $purchase->update(['date' => '2026-02-28']);
            $this->fail('Se esperaba PeriodoFiscalCerradoException al mover compra a período declarado.');
        } catch (PeriodoFiscalCerradoException $e) {
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(2, $e->periodMonth);
        }
    }

    public function test_updating_purchase_cannot_move_date_out_of_declared_original_period(): void
    {
        // Attack vector: compra en período declarado, intento moverla a período
        // abierto para luego editarla. La verificación del período ORIGINAL
        // debe bloquear esto aunque la nueva fecha sea válida.
        $purchase = $this->makePurchase(['date' => '2026-03-10']);
        $this->closePeriod(2026, 3);

        try {
            $purchase->update(['date' => '2026-04-05']); // abril sigue abierto
            $this->fail('Se esperaba bloqueo por período ORIGINAL cerrado.');
        } catch (PeriodoFiscalCerradoException $e) {
            // El mensaje identifica el período original (marzo), no el nuevo.
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(3, $e->periodMonth);
        }
    }

    // ─── deleting ────────────────────────────────────────────────────

    public function test_deleting_purchase_in_open_period_succeeds(): void
    {
        $purchase = $this->makePurchase(['date' => '2026-03-10']);

        $purchase->delete();

        // SoftDeletes: el registro existe pero con deleted_at != null.
        $this->assertSoftDeleted('purchases', ['id' => $purchase->id]);
    }

    public function test_deleting_purchase_in_declared_period_is_blocked(): void
    {
        $purchase = $this->makePurchase(['date' => '2026-03-10']);
        $this->closePeriod(2026, 3);

        try {
            $purchase->delete();
            $this->fail('Se esperaba PeriodoFiscalCerradoException al borrar compra en período declarado.');
        } catch (PeriodoFiscalCerradoException $e) {
            $this->assertEquals(2026, $e->periodYear);
            $this->assertEquals(3, $e->periodMonth);
        }

        // La compra sigue existiendo (ni borrada física ni lógicamente).
        $this->assertNull($purchase->fresh()->deleted_at);
    }

    // ─── Reapertura como escape hatch ──────────────────────────────────

    public function test_updating_purchase_after_period_reopen_is_allowed(): void
    {
        // Flujo completo: declaro → intento editar (falla) → reabro → editar (ok).
        $purchase = $this->makePurchase(['date' => '2026-03-10']);

        $user = User::factory()->create();
        $period = FiscalPeriod::factory()
            ->forMonth(2026, 3)
            ->declared($user)
            ->create();

        // Intento de edición con período declarado: debe fallar.
        try {
            $purchase->update(['notes' => 'Primer intento']);
            $this->fail('Debió bloquearse antes del reopen.');
        } catch (PeriodoFiscalCerradoException) {
            // ok, esperado.
        }

        // Reabro el período (escape hatch legítimo).
        $period->update([
            'reopened_at' => now(),
            'reopened_by' => $user->id,
            'reopen_reason' => 'Rectificativa',
        ]);

        // Ahora la edición debe pasar.
        $purchase->update(['notes' => 'Post-reopen']);
        $this->assertEquals('Post-reopen', $purchase->fresh()->notes);
    }
}

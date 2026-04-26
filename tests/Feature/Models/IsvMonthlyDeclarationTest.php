<?php

namespace Tests\Feature\Models;

use App\Models\FiscalPeriod;
use App\Models\IsvMonthlyDeclaration;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests del modelo IsvMonthlyDeclaration y su Observer.
 *
 * Cubre tres garantías críticas del diseño:
 *   1. Relaciones y casts coherentes con la tabla (fiscalPeriod, users).
 *   2. Scopes/helpers: active, superseded, forFiscalPeriod, forPeriod,
 *      isActive(), isSuperseded().
 *   3. Inmutabilidad post-insert (Observer):
 *      - Columnas fiscales bloqueadas en update.
 *      - Columnas whitelisted (notes, superseded_*) permitidas.
 *      - Delete bloqueado completamente (incluyendo forceDelete no aplica
 *        porque no hay SoftDeletes).
 *   4. UNIQUE compuesto (fiscal_period_id, is_active):
 *      - Bloquea dos snapshots activos del mismo período.
 *      - Permite N supersedidos + 1 activo del mismo período (flujo
 *        rectificativas múltiples).
 */
class IsvMonthlyDeclarationTest extends TestCase
{
    use RefreshDatabase;

    // ─── Factory + creación básica ─────────────────────────────

    public function test_can_create_declaration_with_factory(): void
    {
        $declaration = IsvMonthlyDeclaration::factory()->create();

        $this->assertDatabaseHas('isv_monthly_declarations', [
            'id' => $declaration->id,
            'fiscal_period_id' => $declaration->fiscal_period_id,
        ]);

        $this->assertNotNull($declaration->declared_at);
        $this->assertNotNull($declaration->declared_by_user_id);
    }

    public function test_totals_are_cast_to_decimal_string(): void
    {
        // decimal:2 → string '1234.56' para precisión exacta (no float).
        $declaration = IsvMonthlyDeclaration::factory()->create([
            'ventas_gravadas' => 1234.56,
        ]);

        $this->assertIsString($declaration->fresh()->ventas_gravadas);
        $this->assertEquals('1234.56', $declaration->fresh()->ventas_gravadas);
    }

    // ─── Relaciones ────────────────────────────────────────────

    public function test_belongs_to_fiscal_period(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 3)->create();
        $declaration = IsvMonthlyDeclaration::factory()->forFiscalPeriod($period)->create();

        $this->assertInstanceOf(FiscalPeriod::class, $declaration->fiscalPeriod);
        $this->assertEquals($period->id, $declaration->fiscalPeriod->id);
    }

    public function test_belongs_to_declared_by_user(): void
    {
        $user = User::factory()->create();
        $declaration = IsvMonthlyDeclaration::factory()
            ->create(['declared_by_user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $declaration->declaredByUser);
        $this->assertEquals($user->id, $declaration->declaredByUser->id);
    }

    public function test_belongs_to_superseded_by_user_is_null_when_active(): void
    {
        $declaration = IsvMonthlyDeclaration::factory()->create();

        $this->assertNull($declaration->supersededByUser);
    }

    public function test_belongs_to_superseded_by_user_when_superseded(): void
    {
        $user = User::factory()->create();
        $declaration = IsvMonthlyDeclaration::factory()
            ->superseded($user)
            ->create();

        $this->assertInstanceOf(User::class, $declaration->supersededByUser);
        $this->assertEquals($user->id, $declaration->supersededByUser->id);
    }

    // ─── Helpers isActive() / isSuperseded() ───────────────────

    public function test_is_active_returns_true_when_not_superseded(): void
    {
        $declaration = IsvMonthlyDeclaration::factory()->create();

        $this->assertTrue($declaration->isActive());
        $this->assertFalse($declaration->isSuperseded());
    }

    public function test_is_superseded_returns_true_when_replaced(): void
    {
        $declaration = IsvMonthlyDeclaration::factory()->superseded()->create();

        $this->assertTrue($declaration->isSuperseded());
        $this->assertFalse($declaration->isActive());
    }

    // ─── Scopes ────────────────────────────────────────────────

    public function test_scope_active_returns_only_vigentes(): void
    {
        // Cada snapshot en un período distinto: el default del factory usa
        // now()->year/month para el FiscalPeriod, y FiscalPeriod tiene UNIQUE
        // en (period_year, period_month). Con `forFiscalPeriod(...)` cada uno
        // apunta a un período propio y evitamos la colisión.
        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod(FiscalPeriod::factory()->forMonth(2026, 1)->create())
            ->create();
        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod(FiscalPeriod::factory()->forMonth(2026, 2)->create())
            ->create();

        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod(FiscalPeriod::factory()->forMonth(2026, 3)->create())
            ->superseded()->create();
        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod(FiscalPeriod::factory()->forMonth(2026, 4)->create())
            ->superseded()->create();
        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod(FiscalPeriod::factory()->forMonth(2026, 5)->create())
            ->superseded()->create();

        $active = IsvMonthlyDeclaration::query()->active()->get();

        $this->assertCount(2, $active);
        $active->each(fn ($d) => $this->assertNull($d->superseded_at));
    }

    public function test_scope_superseded_returns_only_replaced(): void
    {
        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod(FiscalPeriod::factory()->forMonth(2026, 1)->create())
            ->create();
        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod(FiscalPeriod::factory()->forMonth(2026, 2)->create())
            ->create();

        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod(FiscalPeriod::factory()->forMonth(2026, 3)->create())
            ->superseded()->create();
        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod(FiscalPeriod::factory()->forMonth(2026, 4)->create())
            ->superseded()->create();
        IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod(FiscalPeriod::factory()->forMonth(2026, 5)->create())
            ->superseded()->create();

        $superseded = IsvMonthlyDeclaration::query()->superseded()->get();

        $this->assertCount(3, $superseded);
        $superseded->each(fn ($d) => $this->assertNotNull($d->superseded_at));
    }

    public function test_scope_for_fiscal_period_filters_by_period_id(): void
    {
        $periodA = FiscalPeriod::factory()->forMonth(2026, 3)->create();
        $periodB = FiscalPeriod::factory()->forMonth(2026, 4)->create();

        IsvMonthlyDeclaration::factory()->forFiscalPeriod($periodA)->superseded()->create();
        IsvMonthlyDeclaration::factory()->forFiscalPeriod($periodA)->create();
        IsvMonthlyDeclaration::factory()->forFiscalPeriod($periodB)->create();

        $this->assertEquals(2, IsvMonthlyDeclaration::query()->forFiscalPeriod($periodA->id)->count());
        $this->assertEquals(1, IsvMonthlyDeclaration::query()->forFiscalPeriod($periodB->id)->count());
    }

    public function test_scope_for_period_filters_via_fiscal_period_year_month(): void
    {
        $marzo = FiscalPeriod::factory()->forMonth(2026, 3)->create();
        $abril = FiscalPeriod::factory()->forMonth(2026, 4)->create();

        IsvMonthlyDeclaration::factory()->forFiscalPeriod($marzo)->create();
        IsvMonthlyDeclaration::factory()->forFiscalPeriod($abril)->create();

        $this->assertEquals(1, IsvMonthlyDeclaration::query()->forPeriod(2026, 3)->count());
        $this->assertEquals(1, IsvMonthlyDeclaration::query()->forPeriod(2026, 4)->count());
        $this->assertEquals(0, IsvMonthlyDeclaration::query()->forPeriod(2026, 5)->count());
    }

    // ─── Observer: inmutabilidad de columnas fiscales ──────────

    public function test_observer_blocks_update_of_fiscal_column(): void
    {
        $declaration = IsvMonthlyDeclaration::factory()->create([
            'ventas_gravadas' => 100000,
        ]);

        try {
            $declaration->update(['ventas_gravadas' => 999999]);
            $this->fail('Se esperaba RuntimeException al mutar ventas_gravadas.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ventas_gravadas', $e->getMessage());
            $this->assertStringContainsString('inmutable', strtolower($e->getMessage()));
        }

        $this->assertEquals('100000.00', $declaration->fresh()->ventas_gravadas);
    }

    public function test_observer_blocks_update_of_fiscal_period_id(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 3)->create();
        $otherPeriod = FiscalPeriod::factory()->forMonth(2026, 4)->create();

        $declaration = IsvMonthlyDeclaration::factory()->forFiscalPeriod($period)->create();

        try {
            $declaration->update(['fiscal_period_id' => $otherPeriod->id]);
            $this->fail('Se esperaba RuntimeException al mover el snapshot de período.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fiscal_period_id', $e->getMessage());
        }

        $this->assertEquals($period->id, $declaration->fresh()->fiscal_period_id);
    }

    public function test_observer_blocks_update_of_declared_at(): void
    {
        $declaration = IsvMonthlyDeclaration::factory()->create();

        $this->expectException(RuntimeException::class);

        $declaration->update(['declared_at' => now()->subYear()]);
    }

    // ─── Observer: columnas whitelisted permitidas ─────────────

    public function test_observer_allows_update_of_notes(): void
    {
        $declaration = IsvMonthlyDeclaration::factory()->create(['notes' => null]);

        $declaration->update(['notes' => 'Acuse SIISAR recibido por correo tarde']);

        $this->assertEquals(
            'Acuse SIISAR recibido por correo tarde',
            $declaration->fresh()->notes
        );
    }

    public function test_observer_allows_update_of_superseded_fields(): void
    {
        $declaration = IsvMonthlyDeclaration::factory()->create();
        $user = User::factory()->create();
        $supersededAt = now();

        $declaration->update([
            'superseded_at' => $supersededAt,
            'superseded_by_user_id' => $user->id,
        ]);

        $fresh = $declaration->fresh();
        $this->assertNotNull($fresh->superseded_at);
        $this->assertEquals($user->id, $fresh->superseded_by_user_id);
        $this->assertTrue($fresh->isSuperseded());
    }

    public function test_observer_allows_combined_update_of_whitelisted_columns(): void
    {
        $declaration = IsvMonthlyDeclaration::factory()->create();
        $user = User::factory()->create();

        $declaration->update([
            'notes' => 'Reemplazada por rectificativa',
            'superseded_at' => now(),
            'superseded_by_user_id' => $user->id,
        ]);

        $fresh = $declaration->fresh();
        $this->assertEquals('Reemplazada por rectificativa', $fresh->notes);
        $this->assertTrue($fresh->isSuperseded());
    }

    public function test_observer_blocks_mixed_update_with_forbidden_column(): void
    {
        $declaration = IsvMonthlyDeclaration::factory()->create();

        $this->expectException(RuntimeException::class);

        // Nota válida + compras_gravadas prohibida → todo el update se rechaza.
        $declaration->update([
            'notes' => 'intento mixto',
            'compras_gravadas' => 1,
        ]);
    }

    // ─── Observer: borrado bloqueado ───────────────────────────

    public function test_observer_blocks_delete(): void
    {
        $declaration = IsvMonthlyDeclaration::factory()->create();

        try {
            $declaration->delete();
            $this->fail('Se esperaba RuntimeException al borrar declaración.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('permanentes', strtolower($e->getMessage()));
        }

        $this->assertDatabaseHas('isv_monthly_declarations', ['id' => $declaration->id]);
    }

    // ─── UNIQUE compuesto (fiscal_period_id, is_active) ────────

    public function test_cannot_have_two_active_snapshots_for_same_period(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 3)->create();
        IsvMonthlyDeclaration::factory()->forFiscalPeriod($period)->create();

        $this->expectException(QueryException::class);

        IsvMonthlyDeclaration::factory()->forFiscalPeriod($period)->create();
    }

    public function test_can_have_multiple_superseded_plus_one_active_for_same_period(): void
    {
        // Simula el flujo real: declaración original + 2 rectificativas (2
        // supersedidas) + 1 activa. El UNIQUE debe permitir este caso.
        $period = FiscalPeriod::factory()->forMonth(2026, 3)->create();

        IsvMonthlyDeclaration::factory()->forFiscalPeriod($period)->superseded()->create();
        IsvMonthlyDeclaration::factory()->forFiscalPeriod($period)->superseded()->create();
        IsvMonthlyDeclaration::factory()->forFiscalPeriod($period)->superseded()->create();
        $activa = IsvMonthlyDeclaration::factory()->forFiscalPeriod($period)->create();

        $this->assertEquals(4, IsvMonthlyDeclaration::query()->forFiscalPeriod($period->id)->count());
        $this->assertEquals(1, IsvMonthlyDeclaration::query()->forFiscalPeriod($period->id)->active()->count());
        $this->assertEquals(3, IsvMonthlyDeclaration::query()->forFiscalPeriod($period->id)->superseded()->count());
        $this->assertTrue($activa->fresh()->isActive());
    }

    public function test_different_periods_can_each_have_an_active_snapshot(): void
    {
        $marzo = FiscalPeriod::factory()->forMonth(2026, 3)->create();
        $abril = FiscalPeriod::factory()->forMonth(2026, 4)->create();

        // No debe lanzar nada: UNIQUE es por (fiscal_period_id, is_active).
        IsvMonthlyDeclaration::factory()->forFiscalPeriod($marzo)->create();
        IsvMonthlyDeclaration::factory()->forFiscalPeriod($abril)->create();

        $this->assertEquals(2, IsvMonthlyDeclaration::query()->active()->count());
    }
}

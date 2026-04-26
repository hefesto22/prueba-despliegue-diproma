<?php

namespace Tests\Feature\Services\Establishments;

use App\Models\Establishment;
use App\Models\User;
use App\Services\Establishments\EstablishmentResolver;
use App\Services\Establishments\Exceptions\NoActiveEstablishmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre la decision table de EstablishmentResolver:
 *
 *   user?        | default_establishment_id | default activa | matriz activa | resultado
 *   ─────────────┼──────────────────────────┼────────────────┼───────────────┼───────────────────────────────
 *   autenticado  | seteado                  | sí             | n/a           | defaultEstablishment del user
 *   autenticado  | seteado                  | no             | sí            | matriz (degrada por is_active)
 *   autenticado  | null                     | n/a            | sí            | matriz (fallback)
 *   autenticado  | null                     | n/a            | no            | NoActiveEstablishmentException
 *   null (job)   | n/a                      | n/a            | sí            | matriz (fallback)
 *   null (job)   | n/a                      | n/a            | no            | NoActiveEstablishmentException
 *
 * Más un caso integración que valida que `resolve()` (sin user explícito) usa
 * correctamente el AuthFactory inyectado para levantar al user autenticado.
 */
class EstablishmentResolverTest extends TestCase
{
    use RefreshDatabase;

    private EstablishmentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(EstablishmentResolver::class);
    }

    // ─── resolveForUser: user con default asignado ────────────────────

    public function test_resolves_user_default_establishment_when_assigned(): void
    {
        $sucursal = Establishment::factory()->create(['name' => 'Sucursal La Ceiba']);
        $user = User::factory()->withEstablishment($sucursal)->create();

        $resolved = $this->resolver->resolveForUser($user);

        $this->assertTrue($resolved->is($sucursal));
        $this->assertSame('Sucursal La Ceiba', $resolved->name);
    }

    // ─── resolveForUser: user sin default → fallback matriz ───────────

    public function test_falls_back_to_main_when_user_has_no_default(): void
    {
        $matriz = Establishment::factory()->main()->create();
        $user = User::factory()->create(['default_establishment_id' => null]);

        $resolved = $this->resolver->resolveForUser($user);

        $this->assertTrue($resolved->is($matriz));
        $this->assertTrue($resolved->is_main);
    }

    // ─── resolveForUser: user null → fallback matriz ──────────────────

    public function test_falls_back_to_main_when_user_is_null(): void
    {
        $matriz = Establishment::factory()->main()->create();

        $resolved = $this->resolver->resolveForUser(null);

        $this->assertTrue($resolved->is($matriz));
    }

    // ─── resolveForUser: sin default y sin matriz → excepción ─────────

    public function test_throws_when_user_has_no_default_and_no_main_exists(): void
    {
        $user = User::factory()->create(['default_establishment_id' => null]);

        try {
            $this->resolver->resolveForUser($user);
            $this->fail('Se esperaba NoActiveEstablishmentException, no se lanzó.');
        } catch (NoActiveEstablishmentException $e) {
            $this->assertSame(
                $user->id,
                $e->userId,
                'La excepción debe exponer el userId para diagnóstico en logs/Sentry.'
            );
            $this->assertStringContainsString("usuario #{$user->id}", $e->getMessage());
        }
    }

    public function test_throws_with_null_user_id_when_null_user_and_no_main(): void
    {
        try {
            $this->resolver->resolveForUser(null);
            $this->fail('Se esperaba NoActiveEstablishmentException, no se lanzó.');
        } catch (NoActiveEstablishmentException $e) {
            $this->assertNull($e->userId);
            $this->assertStringContainsString('No hay usuario autenticado', $e->getMessage());
        }
    }

    // ─── Filtro is_active: degradar a matriz si default está desactivada ─

    /**
     * Si el admin desactiva la sucursal default del user (sin limpiar la
     * asignación), el resolver debe degradar a la matriz activa en vez de
     * retornar la sucursal apagada — escribir en una sucursal desactivada
     * genera inconsistencias entre kardex y reportes que filtran is_active.
     */
    public function test_falls_back_to_main_when_user_default_establishment_is_inactive(): void
    {
        $matriz = Establishment::factory()->main()->create();
        $sucursalDesactivada = Establishment::factory()->create(['is_active' => false]);

        $user = User::factory()->withEstablishment($sucursalDesactivada)->create();

        $resolved = $this->resolver->resolveForUser($user);

        $this->assertTrue(
            $resolved->is($matriz),
            'Sucursal default desactivada debe degradar a matriz, no retornarse.'
        );
    }

    /**
     * Si tanto la default del user como la matriz están desactivadas, el
     * resolver debe lanzar excepción explícita en vez de retornar cualquier
     * sucursal "apagada" — el sistema queda sin destino válido para escribir.
     */
    public function test_throws_when_user_default_and_main_are_both_inactive(): void
    {
        Establishment::factory()->main()->create(['is_active' => false]);
        $sucursalDesactivada = Establishment::factory()->create(['is_active' => false]);

        $user = User::factory()->withEstablishment($sucursalDesactivada)->create();

        $this->expectException(NoActiveEstablishmentException::class);
        $this->resolver->resolveForUser($user);
    }

    /**
     * Si solo existe matriz desactivada y el user no tiene default, no hay
     * destino válido — excepción.
     */
    public function test_throws_when_only_inactive_main_exists_and_user_has_no_default(): void
    {
        Establishment::factory()->main()->create(['is_active' => false]);
        $user = User::factory()->create(['default_establishment_id' => null]);

        $this->expectException(NoActiveEstablishmentException::class);
        $this->resolver->resolveForUser($user);
    }

    // ─── resolve (vía auth) integración con AuthFactory ───────────────

    /**
     * Valida que `resolve()` (sin argumentos) consulta el AuthFactory
     * inyectado y levanta al user autenticado correctamente.
     *
     * Este test cubre el contract del método público principal — el que
     * llaman los Services en producción cuando no tienen un user explícito
     * a la mano (SaleService::process, etc.).
     */
    public function test_resolve_uses_authenticated_user_from_auth_factory(): void
    {
        $sucursal = Establishment::factory()->create(['name' => 'Sucursal Comayagua']);
        $user = User::factory()->withEstablishment($sucursal)->create();

        $this->actingAs($user);

        // Re-resolver el Resolver después del actingAs para que el AuthFactory
        // inyectado tenga el user autenticado en su guard.
        $resolver = app(EstablishmentResolver::class);

        $resolved = $resolver->resolve();

        $this->assertTrue($resolved->is($sucursal));
    }
}

<?php

namespace Tests\Feature\Models;

use App\Models\Establishment;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cubre el contrato del modelo User con foco en los bugs detectados
 * durante F6a.5 — blindar contra regresiones.
 *
 * Bugs cubiertos:
 *   1) Doble hash en UserForm: si el form tuviera
 *      `dehydrateStateUsing(Hash::make(...))` junto al cast 'hashed',
 *      la password queda doble-hasheada y `Hash::check()` siempre falla.
 *   2) canAccessPanel hardcodeando roles: si la implementación chequea
 *      roles específicos (super_admin || panel_user), cualquier rol
 *      nuevo (admin, cajero, vendedor, contador) queda bloqueado.
 */
class UserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ─── Regresión #1: doble hash de password ─────────────────────────

    /**
     * Simula el flujo que ejecuta Filament UserForm al crear un usuario:
     * se pasa la password en texto plano y el cast 'password' => 'hashed'
     * del modelo la hashea UNA SOLA VEZ al guardar.
     *
     * Si alguien reintrodujera `dehydrateStateUsing(Hash::make(...))` en
     * el form el string quedaría doble-hasheado y este test fallaría —
     * exactamente lo que pasó en F6a.5 con el usuario Antomy.
     */
    public function test_password_set_via_mass_assignment_is_authenticatable(): void
    {
        $user = User::create([
            'name' => 'Test Admin',
            'email' => 'test-admin@example.com',
            'password' => 'secret1234',
            'is_active' => true,
        ]);

        $user->refresh();

        // El hash almacenado debe ser un bcrypt válido de 60 caracteres.
        $this->assertIsString($user->password);
        $this->assertSame(60, strlen($user->password), 'Password debe ser un bcrypt de 60 chars — un hash más largo indica doble hash.');

        // Y el plaintext original debe validar contra ese hash.
        $this->assertTrue(
            Hash::check('secret1234', $user->password),
            'Hash::check debe retornar true — si falla, la password se está hasheando dos veces.'
        );
    }

    /**
     * Al actualizar la password de un usuario existente el cast debe
     * aplicarse de nuevo una sola vez — blinda contra regresión en el
     * flujo de edit del form.
     */
    public function test_password_updated_via_mass_assignment_is_authenticatable(): void
    {
        $user = User::factory()->create();

        $user->update(['password' => 'nuevaClave99']);
        $user->refresh();

        $this->assertSame(60, strlen($user->password));
        $this->assertTrue(Hash::check('nuevaClave99', $user->password));
    }

    // ─── Regresión #2: canAccessPanel no hardcodear roles ─────────────

    public function test_can_access_panel_when_active_with_admin_role(): void
    {
        Role::findOrCreate('admin', 'web');

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        $panel = Filament::getPanel('admin');

        $this->assertTrue(
            $user->canAccessPanel($panel),
            'Un usuario activo con rol "admin" debe poder entrar al panel — si falla, canAccessPanel está hardcodeando roles.'
        );
    }

    public function test_can_access_panel_with_any_non_hardcoded_role(): void
    {
        // Cualquier rol arbitrario debe permitir acceso — canAccessPanel
        // es un gate binario (activo + tiene rol), no un filtro por rol.
        // Los permisos finos los resuelven Policies + Shield.
        Role::findOrCreate('cajero', 'web');

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('cajero');

        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_can_access_panel_with_super_admin_role(): void
    {
        Role::findOrCreate(Utils::getSuperAdminName(), 'web');

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Utils::getSuperAdminName());

        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_cannot_access_panel_when_active_but_has_no_roles(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->assertFalse(
            $user->canAccessPanel(Filament::getPanel('admin')),
            'Un usuario sin roles asignados no debe poder entrar al panel.'
        );
    }

    public function test_cannot_access_panel_when_inactive_even_with_role(): void
    {
        Role::findOrCreate('admin', 'web');

        $user = User::factory()->create(['is_active' => false]);
        $user->assignRole('admin');

        $this->assertFalse(
            $user->canAccessPanel(Filament::getPanel('admin')),
            'Un usuario inactivo nunca debe poder entrar, tenga el rol que tenga.'
        );
    }

    // ─── F6a.5 sub-step 7: relación defaultEstablishment ──────────────

    /**
     * La relación Eloquent `defaultEstablishment` debe cargar el modelo
     * Establishment correcto cuando el usuario tiene asignada una sucursal.
     * Es el path que lee EstablishmentResolver antes del fallback a matriz.
     */
    public function test_default_establishment_relation_returns_assigned_establishment(): void
    {
        $sucursal = Establishment::factory()->create(['name' => 'Sucursal Choluteca']);

        $user = User::factory()
            ->withEstablishment($sucursal)
            ->create();

        $this->assertNotNull($user->defaultEstablishment);
        $this->assertTrue($user->defaultEstablishment->is($sucursal));
        $this->assertSame('Sucursal Choluteca', $user->defaultEstablishment->name);
    }

    /**
     * Cuando el usuario no tiene sucursal asignada, el helper
     * `activeEstablishment()` debe retornar null — NO explotar.
     *
     * Los callers (Services) deben usar EstablishmentResolver::resolveForUser()
     * para obtener una sucursal válida con fallback a matriz; pero el helper
     * directo no puede lanzar excepción porque se usa en contextos de lectura
     * (UI, widgets, switcher badge) donde null es un estado legítimo.
     */
    public function test_active_establishment_helper_returns_null_when_no_default(): void
    {
        $user = User::factory()->create(['default_establishment_id' => null]);

        $this->assertNull($user->activeEstablishment());
    }

    /**
     * Cuando sí tiene asignación, `activeEstablishment()` debe retornar el
     * Establishment — comportamiento simétrico al caso null.
     */
    public function test_active_establishment_helper_returns_establishment_when_default_is_set(): void
    {
        $sucursal = Establishment::factory()->create();
        $user = User::factory()->withEstablishment($sucursal)->create();

        $active = $user->activeEstablishment();

        $this->assertInstanceOf(Establishment::class, $active);
        $this->assertTrue($active->is($sucursal));
    }
}

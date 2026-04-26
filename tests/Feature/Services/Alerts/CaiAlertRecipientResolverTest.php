<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Alerts;

use App\Authorization\CustomPermission;
use App\Models\User;
use App\Services\Alerts\CaiAlertRecipientResolver;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cubre CaiAlertRecipientResolver — el punto único de política de destinatarios
 * para alertas del módulo CAI. Antes de este refactor, la misma lógica vivía
 * duplicada en SendCaiAlertsJob y ExecuteCaiFailoverJob. Estos tests blindan
 * la invariante de política para que cualquier cambio involuntario (filtro
 * de inactivos, dedup, super_admin) se detecte aislado del resto del flujo.
 *
 * Contrato observable del resolver:
 *   1. Retorna usuarios activos con permiso vía rol
 *   2. Retorna usuarios activos con permiso directo
 *   3. Retorna super_admins activos (aunque no tengan el permiso asignado)
 *   4. Dedupea cuando un usuario cumple múltiples criterios
 *   5. Excluye usuarios inactivos sin importar por qué camino cumplirían
 *   6. Retorna colección vacía si el permiso no existe en DB (seeder no corrido)
 *   7. Retorna colección vacía si el permiso existe pero nadie lo tiene
 *   8. Combina múltiples fuentes sin duplicar ni perder usuarios
 */
class CaiAlertRecipientResolverTest extends TestCase
{
    use RefreshDatabase;

    private CaiAlertRecipientResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        // Permiso custom que el seeder normalmente crearía.
        Permission::findOrCreate(CustomPermission::ManageCai->value, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->resolver = app(CaiAlertRecipientResolver::class);
    }

    public function test_retorna_usuarios_activos_con_permiso_via_rol(): void
    {
        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $contador = User::factory()->create(['is_active' => true]);
        $contador->assignRole('contador');

        $ids = $this->resolver->resolve()->pluck('id')->all();

        $this->assertContains($contador->id, $ids);
    }

    public function test_retorna_usuarios_activos_con_permiso_directo(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(CustomPermission::ManageCai->value);

        $ids = $this->resolver->resolve()->pluck('id')->all();

        $this->assertContains($user->id, $ids);
    }

    public function test_retorna_super_admins_activos_aunque_no_tengan_el_permiso(): void
    {
        $superRole = Role::create(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($superRole);

        $ids = $this->resolver->resolve()->pluck('id')->all();

        $this->assertContains($admin->id, $ids);
    }

    public function test_dedupea_usuario_con_rol_y_permiso_directo(): void
    {
        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('contador');
        $user->givePermissionTo(CustomPermission::ManageCai->value);

        $resolved = $this->resolver->resolve();

        $this->assertCount(1, $resolved->where('id', $user->id));
    }

    public function test_dedupea_super_admin_que_tambien_tiene_el_permiso_custom(): void
    {
        $superRole = Role::create(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        $contadorRole = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $contadorRole->givePermissionTo(CustomPermission::ManageCai->value);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($superRole);
        $admin->assignRole($contadorRole);

        $resolved = $this->resolver->resolve();

        $this->assertCount(1, $resolved->where('id', $admin->id));
    }

    public function test_excluye_usuarios_inactivos_por_cualquier_camino(): void
    {
        $role = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $role->givePermissionTo(CustomPermission::ManageCai->value);

        $superRole = Role::create(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);

        // Inactivo vía rol
        $inactivoRol = User::factory()->create(['is_active' => false]);
        $inactivoRol->assignRole('contador');

        // Inactivo vía permiso directo
        $inactivoDirecto = User::factory()->create(['is_active' => false]);
        $inactivoDirecto->givePermissionTo(CustomPermission::ManageCai->value);

        // Super admin inactivo
        $inactivoAdmin = User::factory()->create(['is_active' => false]);
        $inactivoAdmin->assignRole($superRole);

        $ids = $this->resolver->resolve()->pluck('id')->all();

        $this->assertNotContains($inactivoRol->id, $ids);
        $this->assertNotContains($inactivoDirecto->id, $ids);
        $this->assertNotContains($inactivoAdmin->id, $ids);
    }

    public function test_retorna_vacio_si_permiso_no_existe_en_db(): void
    {
        // Borrar el permiso que el setUp creó.
        Permission::where('name', CustomPermission::ManageCai->value)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Aun habiendo super_admins activos, si el permiso no existe el
        // resolver degrada silenciosamente a vacío (loguea warning, no
        // explota). Es la señal a oncall de que el seeder no corrió.
        $superRole = Role::create(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($superRole);

        $resolved = $this->resolver->resolve();

        $this->assertTrue($resolved->isEmpty());
    }

    public function test_retorna_vacio_si_permiso_existe_pero_nadie_lo_tiene(): void
    {
        // Permiso existe (setUp), pero ningún usuario lo tiene ni es super_admin.
        User::factory()->create(['is_active' => true]);
        User::factory()->create(['is_active' => true]);

        $resolved = $this->resolver->resolve();

        $this->assertTrue($resolved->isEmpty());
    }

    public function test_combina_multiples_fuentes_sin_duplicar_ni_perder_usuarios(): void
    {
        $contadorRole = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $contadorRole->givePermissionTo(CustomPermission::ManageCai->value);
        $superRole = Role::create(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);

        // Vía rol
        $viaRol = User::factory()->create(['is_active' => true]);
        $viaRol->assignRole('contador');

        // Vía permiso directo
        $viaDirecto = User::factory()->create(['is_active' => true]);
        $viaDirecto->givePermissionTo(CustomPermission::ManageCai->value);

        // Super admin
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($superRole);

        // Usuario activo SIN ningún camino — no debe aparecer.
        $sinPermiso = User::factory()->create(['is_active' => true]);

        $ids = $this->resolver->resolve()->pluck('id')->all();

        $this->assertContains($viaRol->id, $ids);
        $this->assertContains($viaDirecto->id, $ids);
        $this->assertContains($admin->id, $ids);
        $this->assertNotContains($sinPermiso->id, $ids);

        // Sin duplicados — tres usuarios distintos, tres entradas.
        $this->assertCount(3, array_unique($ids));
    }
}

<?php

namespace Tests\Feature\Database\Seeders;

use App\Authorization\CustomPermission;
use Database\Seeders\CustomPermissionsSeeder;
use Database\Seeders\RolesAndSuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Issue:SpecialInvoice (factura de casos fuera de manejo de sistema) es solo
 * de super_admin. Con define_via_gate = false super_admin NO pasa por
 * Gate::before: si el seeder no le asigna el permiso, el botón no aparece.
 */
class SpecialInvoicePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_permissions_seeder_se_lo_da_a_super_admin(): void
    {
        $this->seed(CustomPermissionsSeeder::class);

        $superAdmin = Role::findByName(RolesAndSuperAdminSeeder::ROLE_SUPER_ADMIN, 'web');

        $this->assertTrue($superAdmin->hasPermissionTo(CustomPermission::IssueSpecialInvoice->value));
    }

    public function test_seeder_de_roles_no_se_lo_da_al_admin(): void
    {
        Permission::firstOrCreate(['name' => 'View:PointOfSale', 'guard_name' => 'web']);
        $this->seed(CustomPermissionsSeeder::class);
        $this->seed(RolesAndSuperAdminSeeder::class);

        $admin = Role::findByName(RolesAndSuperAdminSeeder::ROLE_ADMIN, 'web');
        $superAdmin = Role::findByName(RolesAndSuperAdminSeeder::ROLE_SUPER_ADMIN, 'web');

        $this->assertTrue($admin->hasPermissionTo('View:PointOfSale'));
        $this->assertFalse($admin->hasPermissionTo(CustomPermission::IssueSpecialInvoice->value));
        $this->assertTrue($superAdmin->hasPermissionTo(CustomPermission::IssueSpecialInvoice->value));
    }
}

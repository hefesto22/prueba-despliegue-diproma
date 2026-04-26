<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Seeders;

use App\Authorization\CustomPermission;
use Database\Seeders\CustomPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Cubre CustomPermissionsSeeder:
 *   - Lee TODOS los cases del enum `App\Authorization\CustomPermission` y crea
 *     cada permiso con el guard default.
 *   - Es idempotente (correr dos veces no duplica).
 *
 * NO testea nombres específicos (Declare:FiscalPeriod, Manage:Cai, etc.) porque
 * el contrato del seeder es "refleja el enum en DB" — los nombres son decisión
 * del enum, no del seeder. Si alguien agrega un case al enum, el seeder debe
 * crearlo sin necesidad de cambiar este test.
 */
class CustomPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_todos_los_permisos_declarados_en_el_enum(): void
    {
        $declarados = CustomPermission::names();

        // Pre-condición del test: el enum debe tener al menos un case para que
        // el assert tenga sentido. Si esto falla es bug del enum, no del seeder.
        $this->assertNotEmpty(
            $declarados,
            'El enum CustomPermission debe tener al menos 1 case para correr este test'
        );

        $this->artisan('db:seed', ['--class' => CustomPermissionsSeeder::class]);

        $guard = config('auth.defaults.guard', 'web');

        foreach ($declarados as $name) {
            $this->assertDatabaseHas('permissions', [
                'name' => $name,
                'guard_name' => $guard,
            ]);
        }

        // El conteo en DB debe ser exactamente el número declarado
        // (rules out: el seeder creó permisos extra o alguno no se creó).
        $this->assertSame(
            count($declarados),
            Permission::query()->whereIn('name', $declarados)->count(),
        );
    }

    public function test_es_idempotente(): void
    {
        $this->artisan('db:seed', ['--class' => CustomPermissionsSeeder::class]);
        $countDespuesPrimeraCorrida = Permission::query()->count();

        $this->artisan('db:seed', ['--class' => CustomPermissionsSeeder::class]);
        $countDespuesSegundaCorrida = Permission::query()->count();

        $this->assertSame(
            $countDespuesPrimeraCorrida,
            $countDespuesSegundaCorrida,
            'Correr el seeder dos veces no debe duplicar permisos'
        );
    }
}

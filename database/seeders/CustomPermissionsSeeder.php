<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Authorization\CustomPermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registra en DB los permisos CUSTOM del dominio que Shield NO detecta automáticamente
 * al correr `shield:generate` (porque no son métodos estándar del scaffold
 * viewAny/view/create/update/delete/...).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * FUENTE DE VERDAD: el enum `App\Authorization\CustomPermission`.
 * ─────────────────────────────────────────────────────────────────────────
 * Este seeder lee DIRECTAMENTE del enum, no del config. Razón:
 *   - El config es un "espejo" del enum que pobla `CustomPermissionServiceProvider`
 *     en runtime para que Shield (que lee del config) muestre los checkboxes.
 *   - Leer del enum aquí elimina cualquier dependencia de orden de arranque
 *     (si por algún motivo el provider no corre, el seeder sigue funcionando).
 *   - Single source of truth real: agregar un `case` al enum es lo único que
 *     hace falta. El seeder, el provider y cualquier otro consumidor se actualizan
 *     automáticamente sin tocar nada más.
 *
 * Flujo para agregar un permiso custom nuevo:
 *   1) Agregar un `case` al enum `App\Authorization\CustomPermission`.
 *   2) Correr: `php artisan db:seed --class=CustomPermissionsSeeder` (o `db:seed`
 *      completo en fresh install — este seeder está registrado en DatabaseSeeder).
 *   3) Asignar el permiso al rol correspondiente desde el panel Shield.
 *
 * Idempotente por diseño: firstOrCreate no duplica filas en ejecuciones repetidas.
 *
 * Guards: crea cada permiso en el guard default (`config('auth.defaults.guard')`
 * que en Diproma es `web`). Si algún día se agrega un guard adicional (api, por
 * ejemplo), habría que extender este seeder para iterar guards — no aplica hoy.
 */
class CustomPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        $permissions = CustomPermission::names();

        if ($permissions === []) {
            $this->command?->warn('El enum CustomPermission no tiene cases declarados — nada que registrar.');

            return;
        }

        $created = 0;
        $existing = 0;

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => $guard,
            ]);

            $permission->wasRecentlyCreated ? $created++ : $existing++;
        }

        // Limpiar caché de Spatie para que los nuevos se vean al instante sin reiniciar.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'Custom permissions: %d nuevos, %d existentes (total declarados: %d).',
            $created,
            $existing,
            count($permissions),
        ));
    }
}

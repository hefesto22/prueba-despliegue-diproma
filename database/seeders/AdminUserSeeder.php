<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * DEPRECATED: Este seeder ya no se usa.
 *
 * El super admin ahora se crea con Filament Shield:
 *   php artisan shield:super-admin --panel=admin
 *
 * Para produccion, usar ShieldSeeder:
 *   php artisan shield:seeder --with-users
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Shield maneja la creacion del super admin interactivamente.
        // Ver README para instrucciones de setup.
    }
}

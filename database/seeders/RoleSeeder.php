<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * DEPRECATED: Este seeder ya no se usa.
 *
 * Los roles y permisos ahora se gestionan con Filament Shield:
 *   php artisan shield:generate --all --panel=admin
 *   php artisan shield:super-admin --panel=admin
 *
 * Para produccion, usar ShieldSeeder:
 *   php artisan shield:seeder
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Shield maneja la generacion de roles y permisos automaticamente.
        // Ver README para instrucciones de setup.
    }
}

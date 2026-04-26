<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles y resource permissions se gestionan con Filament Shield:
        //   php artisan shield:generate --all --panel=admin
        //   php artisan shield:super-admin --panel=admin
        //
        // Para produccion, generar el ShieldSeeder:
        //   php artisan shield:seeder --with-users
        //   php artisan db:seed --class=ShieldSeeder
        //
        // CustomPermissionsSeeder lee del enum App\Authorization\CustomPermission
        // y crea los permisos del dominio (Declare:FiscalPeriod, Manage:Cai, etc.) que
        // Shield NO detecta automáticamente. Idempotente. Para agregar un permiso nuevo
        // solo se agrega un `case` al enum — este seeder no necesita cambios.

        $this->call([
            CustomPermissionsSeeder::class,
            CompanySettingSeeder::class,
            SystemUserSeeder::class,
            SpecOptionSeeder::class,
            ProductSeeder::class,
        ]);
    }
}

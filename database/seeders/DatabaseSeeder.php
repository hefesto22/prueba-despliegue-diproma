<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // Cadena estándar — corre tanto en local como en producción.
        // ─────────────────────────────────────────────────────────────────
        //
        // Este seeder deja el sistema listo para usarse sin datos test:
        //   - Permisos del dominio (Custom + Shield).
        //   - Roles + super_admin (Mauricio = admin@gmail.com).
        //   - 4 usuarios operativos demo (Antomy + cajero + contador + técnico),
        //     todos con password `12345678` y cadena realista de created_by.
        //   - Datos de la empresa Diproma + sucursal matriz.
        //   - Usuario "sistema" para jobs automáticos.
        //   - Catálogo base: spec_options + device_categories.
        //
        // NO se siembran productos, clientes, ventas, gastos, facturas, etc.
        // — esos los registra Antomy desde el panel cuando arranca operación.
        //
        // PRE-REQUISITO antes del primer `db:seed`:
        //    php artisan shield:generate --all --panel=admin
        //
        // RolesAndSuperAdminSeeder lo necesita para asignar los permisos de
        // resources/pages/widgets generados por Shield. Si no, abortará con
        // un mensaje claro pidiendo correr el comando.

        $this->call([
            // 1. Permisos del dominio que Shield NO detecta (Declare:FiscalPeriod, etc.).
            //    Lee del enum CustomPermission e inserta los que falten.
            CustomPermissionsSeeder::class,

            // 2. Roles + super_admin (Mauricio = admin@gmail.com) + asignación
            //    de permisos por rol. Idempotente.
            RolesAndSuperAdminSeeder::class,

            // 3. Usuarios operativos demo:
            //    antomy@gmail.com (admin) — created_by = Mauricio
            //    cajero@gmail.com  (cajero) — created_by = Antomy
            //    contador@gmail.com (contador) — created_by = Antomy
            //    tecnico@gmail.com (técnico) — created_by = Antomy
            //    Todos con password `12345678`.
            DemoUsersSeeder::class,

            // 4. Datos de la empresa Diproma + sucursal matriz.
            CompanySettingSeeder::class,

            // 5. Usuario "sistema" — actor automático para jobs / cierres.
            SystemUserSeeder::class,

            // 6. Catálogo base que el sistema necesita para operar:
            //    spec_options (procesador, RAM, etc.) y device_categories
            //    (Laptop, Desktop, Celular, etc. para reparaciones).
            SpecOptionSeeder::class,
            DeviceCategorySeeder::class,
        ]);

        // ─────────────────────────────────────────────────────────────────
        // Seeders OPCIONALES — NO corren por default.
        // ─────────────────────────────────────────────────────────────────
        //
        // Para poblar el catálogo con productos de muestra (laptops, desktops,
        // accesorios) — útil para demos comerciales, NUNCA en producción real:
        //
        //   php artisan db:seed --class=ProductSeeder
        //
        // Si querés re-ejecutar solo los users demo sin tocar el resto:
        //
        //   php artisan db:seed --class=DemoUsersSeeder
    }
}

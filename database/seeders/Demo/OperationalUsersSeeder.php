<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Establishment;
use App\Models\User;
use Database\Seeders\RolesAndSuperAdminSeeder;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea 3 usuarios operativos para datos históricos realistas.
 *
 * Por qué este seeder existe:
 *   - Sin estos usuarios, todo `created_by` en históricos quedaría con
 *     admin@gmail.com (super_admin) o el system user — irreal.
 *   - Cada operación histórica se atribuirá al rol correcto:
 *       Carlos (admin)  → compras, gastos administrativos, gestión productos
 *       Lenin  (contador)→ retenciones ISV, declaraciones, libros fiscales
 *       Sofía  (cajero) → ventas, facturas, sesiones de caja, gastos del día
 *
 * Diseño:
 *   - Idempotente via firstOrCreate por email.
 *   - Password '12345678' (cast 'hashed' del modelo lo hashea — NO Hash::make).
 *   - default_establishment_id = Matriz (único establecimiento hoy).
 *   - Sin password_changed por requerimiento de demo local — mismo criterio
 *     que el super admin.
 *
 * Requisitos previos:
 *   - CompanySettingSeeder (provee Matriz).
 *   - RolesAndSuperAdminSeeder (provee los roles `admin`, `contador`, `cajero`).
 */
class OperationalUsersSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = '12345678';

    public function run(): void
    {
        $matriz = Establishment::where('is_main', true)->firstOrFail();

        $usuarios = [
            [
                'name' => 'Carlos Mendoza',
                'email' => 'carlos.mendoza@diproma.hn',
                'phone' => '9988-1100',
                'role' => RolesAndSuperAdminSeeder::ROLE_ADMIN,
            ],
            [
                'name' => 'Lenin Vega',
                'email' => 'lenin@diproma.hn',
                'phone' => '9988-2200',
                'role' => RolesAndSuperAdminSeeder::ROLE_CONTADOR,
            ],
            [
                'name' => 'Sofía López',
                'email' => 'sofia.lopez@diproma.hn',
                'phone' => '9988-3300',
                'role' => RolesAndSuperAdminSeeder::ROLE_CAJERO,
            ],
        ];

        foreach ($usuarios as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => self::DEFAULT_PASSWORD,
                    'phone' => $data['phone'],
                    'is_active' => true,
                    'default_establishment_id' => $matriz->id,
                ]
            );

            // Re-asegurar atributos críticos si el user ya existía con datos
            // distintos (ej. desactivado o sin sucursal). syncRoles reemplaza
            // limpio para que re-correr el seeder no duplique roles.
            if (! $user->is_active || $user->default_establishment_id !== $matriz->id) {
                $user->update([
                    'is_active' => true,
                    'default_establishment_id' => $matriz->id,
                ]);
            }

            $user->syncRoles([$data['role']]);
        }

        // Limpiar cache de Spatie — los matchers de rol deben ver los cambios
        // sin reiniciar el proceso.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'Usuarios operativos creados: Carlos (admin), Lenin (contador), Sofía (cajero) — sucursal=%s',
            $matriz->name,
        ));
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder de usuarios demo — uno por rol operativo del sistema.
 *
 * PROPÓSITO
 * ─────────
 * Provee un set inicial de usuarios listos para que Mauricio (o cualquier dev)
 * pueda demostrar el sistema sin tener que crearlos manualmente desde el panel
 * cada vez que se hace `migrate:fresh --seed` en local/staging.
 *
 * En PRODUCCIÓN este seeder NO se ejecuta — el `DatabaseSeeder` solo corre
 * lo estrictamente necesario (roles + super_admin + catálogo). Los usuarios
 * reales (Antomy, cajeros, contador, técnico) los crea Mauricio desde el panel
 * con sus emails y datos verdaderos.
 *
 * USUARIOS QUE CREA (todos password = `12345678` para demo)
 * ─────────────────────────────────────────────────────────
 *   - antomy@gmail.com    → rol `admin`     (creado por super_admin Mauricio)
 *   - cajero@gmail.com    → rol `cajero`    (creado por admin Antomy)
 *   - contador@gmail.com  → rol `contador`  (creado por admin Antomy)
 *   - tecnico@gmail.com   → rol `tecnico`   (creado por admin Antomy)
 *
 * El super_admin (Mauricio = admin@gmail.com) NO se crea aquí — viene del
 * `RolesAndSuperAdminSeeder`, que es prerequisito y se llama internamente
 * abajo para que un `db:seed --class=DemoUsersSeeder` standalone funcione.
 *
 * CADENA DE created_by (importante para demos)
 * ────────────────────────────────────────────
 * Reproduce el flujo real: el super_admin contrata al admin operativo, y este
 * último crea al resto del personal. Quedará reflejado en cada User row así:
 *
 *   Mauricio  → created_by = NULL   (raíz)
 *   Antomy    → created_by = Mauricio.id
 *   cajero    → created_by = Antomy.id
 *   contador  → created_by = Antomy.id
 *   tecnico   → created_by = Antomy.id
 *
 * Si el seeder se re-corre con un user ya existente, NO sobreescribimos su
 * `created_by` — Mauricio puede haberlo cambiado a algo válido del panel y
 * borrar esa atribución sería pérdida de datos.
 *
 * IDEMPOTENCIA
 * ────────────
 * `firstOrCreate` por email para no duplicar. `syncRoles` reemplaza limpio si
 * el rol cambió entre runs. Password se setea solo en creación (firstOrCreate
 * no toca atributos en filas existentes).
 *
 * SEGURIDAD
 * ─────────
 * Password default `12345678` es DEBIL A PROPÓSITO — solo demo local. Si
 * alguien corre este seeder en un servidor accesible (staging/producción con
 * datos reales), debe cambiar las constantes ANTES o el sistema queda con
 * 4 cuentas con password trivial.
 */
class DemoUsersSeeder extends Seeder
{
    /**
     * Password default para todos los demo users — débil a propósito.
     *
     * El cast `password => hashed` en User lo procesa al guardar. NO usar
     * `Hash::make()` aquí — produciría doble hash y rompería el login
     * (memoria: feedback_filament_password_no_double_hash).
     */
    private const DEMO_PASSWORD = '12345678';

    private const ANTOMY_EMAIL = 'antomy@gmail.com';
    private const ANTOMY_NAME  = 'Antomy';

    private const CAJERO_EMAIL = 'cajero@gmail.com';
    private const CAJERO_NAME  = 'Cajero Demo';

    private const CONTADOR_EMAIL = 'contador@gmail.com';
    private const CONTADOR_NAME  = 'Contador Demo';

    private const TECNICO_EMAIL = 'tecnico@gmail.com';
    private const TECNICO_NAME  = 'Técnico Demo';

    public function run(): void
    {
        // 1. Pre-requisito: roles + super_admin existen.
        //    Llamamos al seeder en vez de asumirlo — así un `db:seed --class=DemoUsersSeeder`
        //    standalone funciona sin que Mauricio tenga que recordar el orden.
        //    Si ya corrió, es no-op (todos los métodos internos son idempotentes).
        $this->call(RolesAndSuperAdminSeeder::class);

        // 2. Localizar a Mauricio (super_admin) — es el `created_by` de Antomy.
        //    Usamos la constante pública del seeder anterior para no duplicar
        //    el string del email; si Mauricio cambia el dueño del sistema en
        //    el futuro, hay un solo lugar donde tocarlo.
        $mauricio = User::where('email', RolesAndSuperAdminSeeder::SUPER_ADMIN_EMAIL)->first();

        if ($mauricio === null) {
            $this->command?->error(
                'No se encontró el super_admin (' . RolesAndSuperAdminSeeder::SUPER_ADMIN_EMAIL . '). '
                . 'Asegurate que RolesAndSuperAdminSeeder corrió correctamente.'
            );

            return;
        }

        // 3. Creamos toda la cadena en una transacción — si algo falla a
        //    mitad, evitamos quedar con users sin rol o con roles huérfanos.
        DB::transaction(function () use ($mauricio): void {
            // 3.1. Antomy = admin. Su `created_by` es Mauricio (super_admin).
            $antomy = $this->upsertDemoUser(
                email:     self::ANTOMY_EMAIL,
                name:      self::ANTOMY_NAME,
                role:      RolesAndSuperAdminSeeder::ROLE_ADMIN,
                createdBy: $mauricio->id,
            );

            // 3.2. El resto del staff lo "registró" Antomy desde el panel.
            //      Reproduce el flujo real: el admin operativo onboardea al
            //      personal, no el dueño del sistema.
            $this->upsertDemoUser(
                email:     self::CAJERO_EMAIL,
                name:      self::CAJERO_NAME,
                role:      RolesAndSuperAdminSeeder::ROLE_CAJERO,
                createdBy: $antomy->id,
            );

            $this->upsertDemoUser(
                email:     self::CONTADOR_EMAIL,
                name:      self::CONTADOR_NAME,
                role:      RolesAndSuperAdminSeeder::ROLE_CONTADOR,
                createdBy: $antomy->id,
            );

            $this->upsertDemoUser(
                email:     self::TECNICO_EMAIL,
                name:      self::TECNICO_NAME,
                role:      RolesAndSuperAdminSeeder::ROLE_TECNICO,
                createdBy: $antomy->id,
            );
        });

        $this->command?->info(
            'Demo users listos: '
            . self::ANTOMY_EMAIL . ' (admin), '
            . self::CAJERO_EMAIL . ' (cajero), '
            . self::CONTADOR_EMAIL . ' (contador), '
            . self::TECNICO_EMAIL . ' (técnico). '
            . 'Password de todos: ' . self::DEMO_PASSWORD
        );
    }

    /**
     * Crea o ubica un demo user por email y le asigna el rol indicado.
     *
     * Idempotencia:
     *   - `firstOrCreate` no toca filas existentes → password, name y created_by
     *     solo se aplican en la creación. Re-correr el seeder NO resetea cambios
     *     hechos desde el panel.
     *   - `syncRoles([$role])` reemplaza cualquier rol previo por el deseado —
     *     útil si Mauricio agregó un rol extra al user demo en una corrida
     *     anterior y ahora queremos volver al estado limpio.
     */
    private function upsertDemoUser(string $email, string $name, string $role, int $createdBy): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name'       => $name,
                'password'   => self::DEMO_PASSWORD,
                'is_active'  => true,
                'created_by' => $createdBy,
            ],
        );

        // Si el user ya existía pero alguien lo desactivó manualmente, lo
        // reactivamos — para una demo necesitamos los 4 cajones funcionando.
        if (! $user->is_active) {
            $user->update(['is_active' => true]);
        }

        $user->syncRoles([$role]);

        return $user;
    }
}

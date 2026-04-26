<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeder del usuario "sistema" — actor reservado para acciones automatizadas.
 *
 * El sistema necesita un User válido para atribuir el `user_id` en tablas con
 * FK NOT NULL a `users` (cash_movements, activity_log, etc.) cuando la acción
 * la ejecuta un proceso automático en vez de un humano (ej. AutoCloseCashSessionsJob,
 * futuros jobs de expiración o ajustes automáticos).
 *
 * Sin este registro, el job tendría que:
 *   - Atribuir falsamente al cajero que abrió la sesión (deuda de auditoría),
 *   - O hacer la columna nullable (rompe semántica + JOINs existentes),
 *   - O crear un user efímero por job (basura en la tabla).
 *
 * Características del registro:
 *   - email: User::SYSTEM_EMAIL — único punto de localización (constante en User).
 *   - is_active: false — bloqueado por canAccessPanel para que no pueda loguearse.
 *   - sin roles asignados — defensa adicional vs canAccessPanel (que verifica
 *     que el user tenga al menos un rol).
 *   - password: hash inalcanzable de un random largo — no recuperable, no
 *     login posible incluso si alguien activara is_active manualmente.
 *
 * Idempotente: usa firstOrCreate() por email. Correrlo múltiples veces no
 * duplica filas ni resetea el password en cada run.
 */
class SystemUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => User::SYSTEM_EMAIL],
            [
                'name'              => 'Sistema Diproma',
                // Random 64 chars — el cast 'hashed' de User lo procesa al guardar.
                // Nadie tiene este password ni puede recuperarlo (no hay ruta de
                // password reset para este email — está oculto en todos los listados).
                'password'          => Str::random(64),
                'is_active'         => false,
                'phone'             => null,
                'avatar_url'        => null,
                'email_verified_at' => null,
            ],
        );
    }
}

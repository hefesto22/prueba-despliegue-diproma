<?php

namespace App\Services\Establishments;

use App\Models\Establishment;
use App\Models\User;
use App\Services\Establishments\Exceptions\NoActiveEstablishmentException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * Resuelve la sucursal activa para la operación en curso.
 *
 * Orden de resolución:
 *  1. Si el user autenticado tiene default_establishment_id Y esa sucursal
 *     está activa → ésa.
 *  2. Fallback: la matriz (is_main = true) del sistema, solo si está activa.
 *  3. Si ninguna está disponible → NoActiveEstablishmentException.
 *
 * Este servicio es el único punto donde se resuelve "la sucursal por default"
 * para Services y acciones de Filament. Ningún caller debe leer
 * `Auth::user()->default_establishment_id` o `Establishment::main()` directo —
 * pasaría por alto el fallback, el filtro is_active y la excepción tipada.
 *
 * Se inyecta AuthFactory (no la facade estática) para que los tests puedan
 * mockear la autenticación sin depender del estado global de Laravel.
 */
class EstablishmentResolver
{
    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * Resolver la sucursal activa para el usuario autenticado.
     *
     * @throws NoActiveEstablishmentException si no hay sucursal disponible.
     */
    public function resolve(): Establishment
    {
        /** @var User|null $user */
        $user = $this->auth->guard()->user();

        return $this->resolveForUser($user);
    }

    /**
     * Resolver la sucursal activa para un usuario específico.
     *
     * Útil para jobs/commands donde el user autenticado no existe pero se
     * opera en nombre de un user concreto (ej. re-procesar una venta).
     *
     * @throws NoActiveEstablishmentException si no hay sucursal disponible.
     */
    public function resolveForUser(?User $user): Establishment
    {
        // 1. Sucursal default del usuario (cached en relación para no reque­rir query extra)
        if ($user && $user->default_establishment_id) {
            $establishment = $user->defaultEstablishment;

            // Guardia dual:
            //   (a) El ID puede apuntar a una sucursal borrada (FK nullOnDelete
            //       aún no aplicada en la misma request). Si la relación viene
            //       null caemos al fallback matriz en vez de retornar inválido.
            //   (b) El admin puede haber desactivado la sucursal sin limpiar la
            //       asignación del user. Registrar ventas ahí genera
            //       inconsistencias en reportes por sucursal que filtran
            //       is_active — degradamos a la matriz activa en vez de escribir
            //       silenciosamente en una sucursal apagada.
            if ($establishment !== null && $establishment->is_active) {
                return $establishment;
            }
        }

        // 2. Fallback matriz — solo si está activa. Si la matriz está
        //    desactivada el sistema no tiene destino válido para la operación:
        //    fallo explícito es preferible a escribir en una sucursal "apagada".
        $matriz = Establishment::main()->where('is_active', true)->first();
        if ($matriz !== null) {
            return $matriz;
        }

        // 3. Sin matriz activa y sin default válido → fallo explícito
        throw new NoActiveEstablishmentException($user?->id);
    }
}

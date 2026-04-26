<?php

namespace App\Services\Establishments\Exceptions;

/**
 * Se lanza cuando no se puede resolver una sucursal activa para la operación.
 *
 * Causas típicas:
 * - El usuario autenticado no tiene default_establishment_id asignado AND
 *   no existe matriz en el sistema (onboarding incompleto).
 * - La operación se ejecuta sin usuario autenticado (ej. cron, consola) y
 *   no hay matriz configurada.
 *
 * Se prefiere fallar explícitamente en vez de un fallback silencioso porque
 * escribir movimientos en la sucursal equivocada corrompe el kardex y los
 * libros SAR — bugs invisibles que se descubren semanas después auditando.
 */
class NoActiveEstablishmentException extends EstablishmentException
{
    public function __construct(public readonly ?int $userId = null)
    {
        $contexto = $userId
            ? "El usuario #{$userId} no tiene sucursal asignada y no existe una matriz configurada"
            : 'No hay usuario autenticado y no existe una matriz configurada';

        parent::__construct(
            "{$contexto}. "
            . 'Asigne una sucursal al usuario en Administración o configure la matriz de la empresa antes de continuar.'
        );
    }
}

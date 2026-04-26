<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento de dominio disparado cuando un usuario inicia sesion exitosamente.
 *
 * Permite que multiples listeners reaccionen al login sin acoplarse entre si.
 * Ejemplos de uso futuro: notificaciones, geolocalizacion, deteccion de anomalias.
 */
class UserLoggedIn
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $ipAddress,
    ) {}
}

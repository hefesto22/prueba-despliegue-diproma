<?php

namespace App\Listeners;

use App\Events\UserLoggedIn;

/**
 * Registra la informacion del ultimo inicio de sesion del usuario.
 * Escucha el evento de dominio UserLoggedIn, no el evento de Laravel directamente.
 *
 * Para agregar mas comportamiento al login (notificaciones, geolocalizacion, etc.)
 * crear nuevos listeners que escuchen UserLoggedIn sin tocar este archivo.
 */
class RecordUserLogin
{
    public function handle(UserLoggedIn $event): void
    {
        $event->user->update([
            'last_login_at' => now(),
            'last_login_ip' => $event->ipAddress,
        ]);
    }
}

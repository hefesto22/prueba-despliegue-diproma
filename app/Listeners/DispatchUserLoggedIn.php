<?php

namespace App\Listeners;

use App\Events\UserLoggedIn;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Puente entre el evento de Laravel (Login) y nuestro evento de dominio (UserLoggedIn).
 * Traduce el evento del framework a un evento que nuestro dominio entiende.
 */
class DispatchUserLoggedIn
{
    public function handle(Login $event): void
    {
        /** @var User $user */
        $user = $event->user;

        UserLoggedIn::dispatch($user, request()->ip());
    }
}

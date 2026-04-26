<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Panel admin de Filament esta en /admin/login. Cualquier ruta web
        // autenticada (InvoicePrintController, etc.) debe redirigir a ese login
        // cuando el usuario no tiene sesion.
        //
        // Sin esta configuracion Laravel intenta resolver route('login') y lanza
        // RouteNotFoundException → 500 en vez de un redirect limpio.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

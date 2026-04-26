<?php

/*
|--------------------------------------------------------------------------
| Configuracion del modulo de Caja
|--------------------------------------------------------------------------
|
| Parametros operativos del ciclo de caja: auto-cierre nocturno y umbral
| de gracia para conciliacion posterior. Centralizar aqui evita "magic
| numbers" desperdigados por jobs y services.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Auto-cierre de sesiones de caja (AutoCloseCashSessionsJob)
    |--------------------------------------------------------------------------
    |
    | hour:  hora del dia (24h, zona America/Tegucigalpa) en que el scheduler
    |        dispara el cierre automatico de cualquier sesion abierta.
    |
    | enabled: switch global por si se necesita desactivar el auto-cierre en
    |          algun escenario operativo (auditoria especial, jornada extendida
    |          autorizada, etc.) sin tocar el scheduler.
    |
    */
    'auto_close' => [
        'enabled' => env('CASH_AUTO_CLOSE_ENABLED', true),
        'hour'    => env('CASH_AUTO_CLOSE_HOUR', '21:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Umbral de gracia para conciliacion
    |--------------------------------------------------------------------------
    |
    | Dias que el sistema espera antes de bloquear la apertura de una caja
    | nueva en una sucursal con sesion auto-cerrada pendiente de conciliar.
    |
    | Razon del default = 7: cubre fines de semana largos, feriados y vacaciones
    | cortas sin atascar la operacion. Si pasan mas dias sin conciliar, la
    | senal es que el proceso de conciliacion tiene un problema mas grave que
    | exige resolverlo antes de seguir abriendo cajas en esa sucursal.
    |
    */
    'reconciliation_grace_days' => env('CASH_RECONCILIATION_GRACE_DAYS', 7),

];

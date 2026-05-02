<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Días de abandono automático
    |--------------------------------------------------------------------------
    |
    | Después de cuántos días en estado ListoEntrega sin que el cliente
    | recoja, el job MarkAbandonedRepairsJob marca la reparación como
    | Abandonada (estado terminal).
    |
    | Default: 60 días — política aprobada por Mauricio el 2026-05-02.
    | Configurable por env REPAIRS_ABANDONMENT_DAYS.
    |
    */
    'abandonment_days' => (int) env('REPAIRS_ABANDONMENT_DAYS', 60),

    /*
    |--------------------------------------------------------------------------
    | Días de retención de fotos
    |--------------------------------------------------------------------------
    |
    | Después de cuántos días desde el estado terminal (Entregada / Rechazada
    | / Anulada / Abandonada), el job CleanupRepairPhotosJob borra físicamente
    | las fotos del equipo del disk 'public'.
    |
    | Default: 7 días. Configurable por env REPAIRS_PHOTO_RETENTION_DAYS.
    |
    */
    'photo_retention_days' => (int) env('REPAIRS_PHOTO_RETENTION_DAYS', 7),

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modo de facturación
    |--------------------------------------------------------------------------
    |
    | Determina cómo se resuelve el correlativo de factura según las variantes
    | del Acuerdo 481-2017 SAR:
    |
    | - 'centralizado':  una sola numeración correlativa para toda la empresa,
    |                    independiente del establecimiento. El sistema usa el
    |                    CAI activo del documento sin filtrar por sucursal.
    |
    | - 'por_sucursal':  cada establecimiento tiene su propia numeración.
    |                    Requiere que cada venta/factura conozca su
    |                    establishment_id y que exista un CAI por sucursal.
    |
    | El cambio de modo es un evento fiscal que requiere registro actualizado
    | en SAR y declaración jurada. No debe cambiarse en caliente desde el admin.
    | Por eso vive en configuración (.env) y no en base de datos.
    |
    */

    'mode' => env('INVOICING_MODE', 'centralizado'),

];

<?php

/*
|--------------------------------------------------------------------------
| Configuracion Fiscal / SAR Honduras
|--------------------------------------------------------------------------
|
| Metadatos declarados ante SAR (Acuerdo 481-2017) para el software de
| autoimpresion de Comprobantes Fiscales. Estos valores se muestran al pie
| de cada factura impresa y se reportan en la declaracion SIISAR.
|
| Cambios de version del software deben reflejarse aqui antes de desplegar
| para que el metadato impreso coincida con el binario en produccion.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Metadatos del Software (SIISAR)
    |--------------------------------------------------------------------------
    |
    | origin:       nacional | extranjero
    | maintenance:  proveedor_software | obligado_tributario | mixto | tercero
    |
    */
    'software' => [
        'name'        => env('FISCAL_SOFTWARE_NAME', 'OlymPos'),
        'version'     => env('FISCAL_SOFTWARE_VERSION', '1.0.0'),
        'developer'   => env('FISCAL_DEVELOPER_NAME', 'Inversiones Olympo'),
        'origin'      => env('FISCAL_DEVELOPER_ORIGIN', 'nacional'),
        'maintenance' => env('FISCAL_MAINTENANCE_TYPE', 'proveedor_software'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Estructura del Sistema (SIISAR)
    |--------------------------------------------------------------------------
    |
    | Se lee del mismo env que invoicing.mode para garantizar consistencia
    | entre la logica de correlativos y la declaracion ante SAR.
    |
    | Valores: centralizado | regional | por_sucursal
    |
    */
    'structure' => env('INVOICING_MODE', 'centralizado'),

    /*
    |--------------------------------------------------------------------------
    | Modulo de Auditoria
    |--------------------------------------------------------------------------
    |
    | Senal declarativa para SAR: el sistema cuenta con modulo de auditoria.
    | Respaldado por Spatie Activity Log (ver ActivityLog en models).
    |
    */
    'audit_enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Leyendas Obligatorias
    |--------------------------------------------------------------------------
    */
    'footer_legend' => 'La Factura es Beneficio de Todos, ¡Exíjala!',

    /*
    |--------------------------------------------------------------------------
    | QR de Verificacion Publica
    |--------------------------------------------------------------------------
    |
    | URL base que se codifica en el QR impreso. En produccion usa APP_URL.
    | El QR apunta a /facturas/verificar/{integrity_hash}.
    |
    */
    'verify_url_base' => env('FISCAL_VERIFY_URL', env('APP_URL', 'http://localhost')),

];

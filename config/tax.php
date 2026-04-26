<?php

/**
 * Configuración fiscal para Honduras — ISV (Impuesto Sobre Ventas).
 *
 * Fuente legal: Decreto 194-2002 (Ley del ISV), reformado por Decreto 51-2003.
 * Tasa estándar del 15% para bienes gravados.
 * Tasa del 18% aplica solo a bebidas alcohólicas y tabaco (no relevante aquí).
 *
 * Centralizado aquí para que un cambio de tasa SAR se aplique en un solo lugar.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Tasa estándar de ISV
    |--------------------------------------------------------------------------
    | Tasa decimal para cálculos internos (0.15 = 15%).
    */
    'standard_rate' => 0.15,

    /*
    |--------------------------------------------------------------------------
    | Porcentaje para presentación
    |--------------------------------------------------------------------------
    | Porcentaje entero para labels y UI (15 = "15%").
    */
    'standard_percentage' => 15,

    /*
    |--------------------------------------------------------------------------
    | Multiplicador para precio con ISV
    |--------------------------------------------------------------------------
    | Para convertir precio base → precio con ISV: base * multiplier.
    | Para convertir precio con ISV → precio base: con_isv / multiplier.
    */
    'multiplier' => 1.15,

];

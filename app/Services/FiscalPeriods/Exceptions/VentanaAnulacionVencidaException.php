<?php

declare(strict_types=1);

namespace App\Services\FiscalPeriods\Exceptions;

/**
 * Se lanza cuando se intenta anular una factura cuya ventana operativa
 * de anulación ya cerró — REGLA OPERATIVA DIPROMA, estricta vs. SAR.
 *
 * Diferencia con `PeriodoFiscalCerradoException`:
 *   - `PeriodoFiscalCerrado` se lanza cuando el contador YA declaró el período
 *     al SAR (estado `declared` en `fiscal_periods`).
 *   - Esta excepción se lanza cuando, aunque el contador NO haya declarado
 *     todavía, ya pasamos del corte calendárico que Diproma definió como
 *     "cierre operativo" — por defecto, el día 10 del mes siguiente al de la
 *     factura, a partir de las 00:00.
 *
 * MOTIVACIÓN OPERATIVA
 * ────────────────────
 * Sin esta regla había una ventana ambigua el día 10 entre que el cajero
 * podía intentar anular y el contador declaraba (típicamente en la mañana
 * del mismo día). Si el contador declaraba a las 9 AM y un cliente llegaba
 * a las 10 AM, el cajero recibía un error genérico de "período cerrado"
 * pero el cliente reclamaba "veníamos antes del 10". Esta regla cierra esa
 * ambigüedad: a partir de las 00:00 del día 10, anular ya no aplica —
 * independiente de lo que haga el contador.
 *
 * MENSAJE PARA EL USUARIO
 * ───────────────────────
 * El mensaje explica explícitamente:
 *   1. Cuál es la fecha de corte concreta (día/mes/año), no abstracta.
 *   2. A qué período fiscal pertenece la factura.
 *   3. Por qué se cierra antes que el SAR (preparación de la declaración).
 *
 * Esto le da al cajero información completa para responderle al cliente
 * sin tener que llamar al admin/contador.
 */
class VentanaAnulacionVencidaException extends FiscalPeriodException
{
    /**
     * @param  string  $documentLabel   Etiqueta human-readable del documento. Ejemplo:
     *                                  "la factura 000-001-01-00000123".
     * @param  string  $cutoffDate      Fecha de corte en formato d/m/Y. Ejemplo: "10/07/2026".
     * @param  string  $invoicePeriod   Período fiscal del documento en formato m/Y.
     *                                  Ejemplo: "06/2026".
     */
    public function __construct(
        public readonly string $documentLabel,
        public readonly string $cutoffDate,
        public readonly string $invoicePeriod,
    ) {
        parent::__construct(
            "No se puede anular {$documentLabel}: las anulaciones del período "
            . "fiscal {$invoicePeriod} cerraron el {$cutoffDate}. "
            . 'Política operativa Diproma: las anulaciones se aceptan hasta el día 9 '
            . 'del mes siguiente al de la factura, para que el contador prepare la '
            . 'declaración mensual al SAR sin cambios de último momento.'
        );
    }
}

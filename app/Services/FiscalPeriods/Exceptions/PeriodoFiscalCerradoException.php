<?php

namespace App\Services\FiscalPeriods\Exceptions;

/**
 * Se lanza cuando se intenta mutar un documento fiscal de un período
 * cuya declaración ISV ya fue presentada al SAR (período cerrado).
 *
 * Aplica a cualquier documento del período: Factura (anulación), Compra
 * (edición/borrado), Retención ISV recibida (alta/edición/borrado), etc.
 * Una vez declarado el período, su contenido es el registro oficial ante
 * el fisco y no admite mutaciones — la corrección válida es:
 *   1. Reabrir el período (FiscalPeriodService::reopen, requiere motivo).
 *   2. Hacer el cambio.
 *   3. Re-declarar (rectificativa) ante SAR.
 *
 * Para facturas existe la salida adicional de Nota de Crédito sin reabrir
 * el período — esa decisión la maneja el caller, no esta excepción.
 *
 * Referencia: Acuerdo SAR 481-2017 — una vez presentada la declaración
 * ISV del período, las operaciones de ese período se consideran
 * oficialmente registradas y no admiten modificación directa.
 */
class PeriodoFiscalCerradoException extends FiscalPeriodException
{
    /**
     * @param  int     $periodYear     Año del período cerrado.
     * @param  int     $periodMonth    Mes del período cerrado.
     * @param  string  $documentLabel  Descripción human-readable del documento que
     *                                 se intentó mutar. Ejemplos:
     *                                   - "la factura 000-001-01-00000123"
     *                                   - "la compra RI-2026-0001"
     *                                   - "la retención ISV #42"
     *                                 Si se omite, se usa "este documento" como
     *                                 fallback genérico.
     */
    public function __construct(
        public readonly int $periodYear,
        public readonly int $periodMonth,
        public readonly string $documentLabel = 'este documento',
    ) {
        $periodo = str_pad((string) $periodMonth, 2, '0', STR_PAD_LEFT) . "/{$periodYear}";

        parent::__construct(
            "No se puede modificar {$this->documentLabel}: el período fiscal {$periodo} "
            . 'ya fue declarado al SAR. Para corregir, reabra el período como rectificativa '
            . 'o (en caso de facturas) emita una Nota de Crédito.'
        );
    }
}

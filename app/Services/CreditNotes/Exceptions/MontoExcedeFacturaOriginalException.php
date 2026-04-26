<?php

namespace App\Services\CreditNotes\Exceptions;

/**
 * La suma total de la NC (todas sus líneas) excede el total de la factura
 * original considerando NCs previas.
 *
 * Es una validación redundante de seguridad: la validación por línea
 * (CantidadYaAcreditadaException) ya cubre el caso típico, pero este
 * chequeo protege contra inconsistencias en los cálculos (ej. precios
 * que cambiaron entre líneas, descuentos proporcionales, etc.).
 */
class MontoExcedeFacturaOriginalException extends CreditNoteException
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $invoiceNumber,
        public readonly float $totalSolicitado,
        public readonly float $saldoAcreditable,
    ) {
        $solicitado = number_format($totalSolicitado, 2);
        $saldo = number_format($saldoAcreditable, 2);

        parent::__construct(
            "El total de la nota de crédito ({$solicitado}) excede el saldo acreditable "
            . "({$saldo}) de la factura #{$invoiceNumber} (id {$invoiceId})."
        );
    }
}

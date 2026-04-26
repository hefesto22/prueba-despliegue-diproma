<?php

namespace App\Services\Invoicing\DTOs;

use Carbon\CarbonImmutable;

/**
 * Value Object inmutable con el resultado de resolver un correlativo fiscal.
 *
 * Lo retorna cualquier implementación de ResuelveCorrelativoFactura.
 * Lo consume cualquier servicio que emita un documento fiscal SAR (Invoice,
 * CreditNote, etc.) para usarlo como snapshot al crear el registro.
 *
 * El campo $documentNumber contiene el correlativo formateado del documento
 * del tipo SAR que se solicitó al resolver (factura '01', nota de crédito '03',
 * recibo '04', etc.) — por eso el nombre es genérico y no "invoiceNumber".
 */
final class CorrelativoResuelto
{
    public function __construct(
        public readonly string $documentNumber,        // Ej: "001-001-01-00000001"
        public readonly string $cai,                   // Código CAI completo
        public readonly int $caiRangeId,               // FK al rango que lo emitió
        public readonly int $establishmentId,          // FK al establishment resuelto
        public readonly string $emissionPoint,         // Snapshot: XXX
        public readonly CarbonImmutable $caiExpirationDate,
    ) {}
}

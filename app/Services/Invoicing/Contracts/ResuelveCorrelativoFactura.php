<?php

namespace App\Services\Invoicing\Contracts;

use App\Enums\DocumentType;
use App\Services\Invoicing\DTOs\CorrelativoResuelto;
use App\Services\Invoicing\Exceptions\CaiVencidoException;
use App\Services\Invoicing\Exceptions\NoHayCaiActivoException;
use App\Services\Invoicing\Exceptions\RangoCaiAgotadoException;

/**
 * Resuelve el siguiente correlativo de factura (u otro documento fiscal SAR).
 *
 * Implementaciones:
 *   - CorrelativoCentralizado: una numeración global para toda la empresa.
 *   - CorrelativoPorSucursal:  una numeración por establecimiento.
 *
 * El binding activo se decide en AppServiceProvider a partir de config('invoicing.mode').
 *
 * CONTRATO DE TRANSACCIÓN:
 *   Los implementadores DEBEN ejecutarse dentro de una transacción abierta por el llamador
 *   (DB::transactionLevel() > 0). Esto garantiza atomicidad fiscal: si el INSERT del Invoice
 *   falla, el avance del correlativo también hace rollback — nunca se "quema" un folio SAR.
 */
interface ResuelveCorrelativoFactura
{
    /**
     * @param  DocumentType $documentType     Tipo SAR — fuente única de verdad (Acuerdo 481-2017).
     *                                        Factura='01', NotaCredito='03', NotaDebito='04'.
     * @param  int|null     $establishmentId  Contexto de establecimiento. Obligatorio en modo por_sucursal,
     *                                        opcional en modo centralizado (se resuelve a matriz si es null).
     *
     * @throws NoHayCaiActivoException   Si no hay CAI activo para el contexto dado.
     * @throws RangoCaiAgotadoException  Si el CAI activo ya usó todos sus folios.
     * @throws CaiVencidoException       Si el CAI activo expiró por fecha.
     */
    public function siguiente(
        DocumentType $documentType = DocumentType::Factura,
        ?int $establishmentId = null,
    ): CorrelativoResuelto;
}

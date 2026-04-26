<?php

namespace App\Exceptions\Fiscal;

use RuntimeException;

/**
 * Se lanza al intentar modificar un campo fiscal protegido de un documento
 * ya emitido (Invoice, CreditNote, etc.).
 *
 * La inmutabilidad post-emisión es requisito SAR: una vez sellado el
 * `emitted_at` y el `integrity_hash`, el documento es el registro legal
 * ante el fisco. Modificar totales, numeración o snapshots rompe la firma
 * del QR de verificación pública y deja el dataset inconsistente frente a
 * una auditoría.
 *
 * Solo deben permitirse cambios en campos operativos (estado de anulación,
 * ruta del PDF generado async). Cualquier intento de modificar un campo
 * fiscal protegido debe fallar ruidosamente en vez de persistir silencioso.
 */
final class DocumentoFiscalInmutableException extends RuntimeException
{
    /**
     * @param  string    $documentType  Nombre corto del modelo (Invoice, CreditNote, ...).
     * @param  int|string $documentId   PK del documento afectado.
     * @param  string[]  $dirtyFields   Campos dirty bloqueados por la whitelist.
     */
    public function __construct(
        public readonly string $documentType,
        public readonly int|string $documentId,
        public readonly array $dirtyFields,
    ) {
        parent::__construct(sprintf(
            'Documento fiscal %s #%s ya emitido es inmutable. Campos bloqueados: %s',
            $documentType,
            $documentId,
            implode(', ', $dirtyFields),
        ));
    }
}

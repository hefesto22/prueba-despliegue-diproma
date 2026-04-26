<?php

namespace App\Services\Invoicing\Exceptions;

class NoHayCaiActivoException extends InvoicingException
{
    public function __construct(
        public readonly string $documentType,
        public readonly ?int $establishmentId = null,
    ) {
        $contexto = $establishmentId
            ? "establecimiento #{$establishmentId}"
            : 'empresa (modo centralizado)';

        parent::__construct(
            "No hay un CAI activo para el tipo de documento '{$documentType}' en {$contexto}. "
            . "Registre un nuevo CAI en Administración antes de continuar."
        );
    }
}

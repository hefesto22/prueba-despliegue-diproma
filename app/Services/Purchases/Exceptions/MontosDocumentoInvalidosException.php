<?php

declare(strict_types=1);

namespace App\Services\Purchases\Exceptions;

use DomainException;

/**
 * Los montos transcritos de un documento de compra violan una regla que
 * ningún documento real puede cumplir (negativos, ISV sin base gravada,
 * documento en cero).
 *
 * `$field` nombra la columna culpable para que la capa de UI pueda colgar el
 * mensaje en el campo correcto del formulario en vez de mostrar un error
 * genérico — ver PurchaseForm::amountsRule().
 */
class MontosDocumentoInvalidosException extends DomainException
{
    public function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}

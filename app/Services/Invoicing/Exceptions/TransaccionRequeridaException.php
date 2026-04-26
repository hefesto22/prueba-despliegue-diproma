<?php

namespace App\Services\Invoicing\Exceptions;

use LogicException;

/**
 * Se lanza cuando un resolvedor de correlativo se invoca fuera de una transacción.
 *
 * Garantiza atomicidad fiscal: si falla el INSERT del Invoice después de avanzar
 * el correlativo, sin transacción el folio queda "quemado" (hueco en la secuencia SAR).
 */
class TransaccionRequeridaException extends LogicException
{
    public function __construct()
    {
        parent::__construct(
            'El resolvedor de correlativo debe ejecutarse dentro de una transacción activa '
            . '(DB::transaction). Abra la transacción en el llamador antes de invocar siguiente().'
        );
    }
}

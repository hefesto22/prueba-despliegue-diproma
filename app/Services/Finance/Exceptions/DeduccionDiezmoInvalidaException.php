<?php

declare(strict_types=1);

namespace App\Services\Finance\Exceptions;

use DomainException;

final class DeduccionDiezmoInvalidaException extends DomainException
{
    public function __construct(public readonly float $amount)
    {
        parent::__construct("Un pago a restar del diezmo no puede ser negativo: {$amount}.");
    }
}

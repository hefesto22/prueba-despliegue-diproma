<?php

declare(strict_types=1);

namespace App\Services\Finance\Exceptions;

use DomainException;

final class MesDiezmoInvalidoException extends DomainException
{
    public function __construct(public readonly int $year, public readonly int $month)
    {
        parent::__construct(sprintf(
            'No se puede sacar el diezmo de %04d-%02d: el mes todavía no empieza.',
            $year,
            $month,
        ));
    }
}

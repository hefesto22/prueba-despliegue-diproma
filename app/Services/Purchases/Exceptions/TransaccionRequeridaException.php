<?php

declare(strict_types=1);

namespace App\Services\Purchases\Exceptions;

use LogicException;

/**
 * Se lanza cuando InternalReceiptNumberGenerator::next() se invoca fuera de una
 * transacción. Sin transacción, si el INSERT posterior falla, el lock se libera
 * y el correlativo generado queda "en el aire" — dos llamadores podrían recibir
 * el mismo NNNN.
 *
 * El llamador debe abrir la transacción ANTES de pedir el número:
 *
 *   DB::transaction(function () {
 *       $numero = $generator->next(now());
 *       Purchase::create([..., 'supplier_invoice_number' => $numero]);
 *   });
 */
class TransaccionRequeridaException extends LogicException
{
    public function __construct()
    {
        parent::__construct(
            'InternalReceiptNumberGenerator debe ejecutarse dentro de una transacción '
            .'activa (DB::transaction). Abra la transacción antes de pedir el correlativo.'
        );
    }
}

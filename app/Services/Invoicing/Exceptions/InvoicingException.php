<?php

namespace App\Services\Invoicing\Exceptions;

use RuntimeException;

/**
 * Raíz de todas las excepciones del módulo de facturación fiscal.
 * Permite capturar selectivamente cualquier fallo del dominio sin atrapar
 * excepciones genéricas del framework.
 */
abstract class InvoicingException extends RuntimeException
{
}

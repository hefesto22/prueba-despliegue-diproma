<?php

namespace App\Services\FiscalPeriods\Exceptions;

use RuntimeException;

/**
 * Raíz de todas las excepciones del módulo de períodos fiscales.
 * Permite capturar selectivamente cualquier fallo del dominio sin atrapar
 * excepciones genéricas del framework.
 */
abstract class FiscalPeriodException extends RuntimeException
{
}

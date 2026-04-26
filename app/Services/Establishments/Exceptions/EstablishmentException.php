<?php

namespace App\Services\Establishments\Exceptions;

use RuntimeException;

/**
 * Raíz de todas las excepciones del módulo de resolución/gestión de sucursales.
 * Permite capturar selectivamente fallos del dominio de sucursales sin atrapar
 * excepciones genéricas del framework.
 */
abstract class EstablishmentException extends RuntimeException
{
}

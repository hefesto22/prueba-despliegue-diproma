<?php

namespace App\Services\CreditNotes\Exceptions;

use RuntimeException;

/**
 * Raíz de todas las excepciones del módulo de Notas de Crédito (SAR tipo '03').
 * Permite capturar selectivamente cualquier fallo del dominio sin atrapar
 * excepciones genéricas del framework.
 */
abstract class CreditNoteException extends RuntimeException
{
}

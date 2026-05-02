<?php

namespace App\Exceptions\Repairs;

use App\Enums\RepairStatus;

/**
 * Excepción de dominio para transiciones de estado inválidas.
 *
 * Lanzada por RepairStatusService cuando un caller intenta saltar de un
 * estado a otro que no está en la lista blanca de `canTransitionTo()`
 * (ej: Recibido → Entregada directo).
 */
class RepairTransitionException extends \DomainException
{
    public function __construct(
        public readonly RepairStatus $from,
        public readonly RepairStatus $to,
        ?string $reason = null,
    ) {
        $msg = "Transición ilegal: {$from->getLabel()} → {$to->getLabel()}";
        if ($reason) {
            $msg .= " ({$reason})";
        }
        parent::__construct($msg);
    }
}

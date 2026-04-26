<?php

namespace App\Services\Alerts\DTOs;

use App\Models\CaiRange;
use App\Services\Alerts\Enums\CaiAlertSeverity;

/**
 * Alerta inmutable de rango CAI cercano al agotamiento.
 *
 * Complementa a CaiExpirationAlert: aquí el riesgo es quedarse sin números
 * correlativos antes de que el CAI caduque por fecha. Los dos escenarios son
 * independientes — un CAI con vigencia 18 meses pero rango de 500 facturas
 * puede agotarse en semanas si la empresa factura mucho.
 */
final class CaiRangeExhaustionAlert
{
    public function __construct(
        public readonly CaiRange $cai,
        public readonly int $remaining,
        public readonly float $remainingPercentage,
        public readonly CaiAlertSeverity $severity,
        public readonly bool $hasSuccessor,
    ) {}

    /**
     * Etiqueta corta para listar en notificaciones.
     * Ej: "CAI 01 · 001-001-01 · quedan 42 facturas (8.4%, sin sucesor)"
     */
    public function shortLabel(): string
    {
        $sucesor = $this->hasSuccessor ? 'con sucesor' : 'sin sucesor';

        return sprintf(
            'CAI %s · %s · quedan %d factura%s (%.1f%%, %s)',
            $this->cai->document_type,
            $this->cai->prefix,
            $this->remaining,
            $this->remaining === 1 ? '' : 's',
            $this->remainingPercentage,
            $sucesor,
        );
    }
}

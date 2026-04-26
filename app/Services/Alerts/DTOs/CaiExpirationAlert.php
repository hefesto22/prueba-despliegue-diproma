<?php

namespace App\Services\Alerts\DTOs;

use App\Models\CaiRange;
use App\Services\Alerts\Enums\CaiAlertSeverity;

/**
 * Alerta inmutable de vencimiento próximo de un CAI.
 *
 * Los checkers producen colecciones de estas DTOs; el Job las consume para
 * construir las notificaciones. Separar DTO de Notification permite:
 *   - Testear la lógica de detección sin tocar canales de entrega.
 *   - Reutilizar el mismo DTO desde el widget del dashboard (una sola query,
 *     múltiples consumidores).
 */
final class CaiExpirationAlert
{
    public function __construct(
        public readonly CaiRange $cai,
        public readonly int $daysUntilExpiration,
        public readonly CaiAlertSeverity $severity,
        public readonly bool $hasSuccessor,
    ) {}

    /**
     * Etiqueta corta para listar en notificaciones.
     * Ej: "CAI 01 · 001-001-01 · vence en 7 días (sin sucesor)"
     */
    public function shortLabel(): string
    {
        $sucesor = $this->hasSuccessor ? 'con sucesor' : 'sin sucesor';

        return sprintf(
            'CAI %s · %s · vence en %d día%s (%s)',
            $this->cai->document_type,
            $this->cai->prefix,
            $this->daysUntilExpiration,
            $this->daysUntilExpiration === 1 ? '' : 's',
            $sucesor,
        );
    }
}

<?php

namespace App\Services\Invoicing\Exceptions;

/**
 * Se lanza cuando un CAI activo queda inutilizable (vencido o agotado) y no
 * existe un sucesor pre-registrado que pueda promoverse para reemplazarlo.
 *
 * Esta excepción representa una condición **crítica de operación**: si no se
 * interviene manualmente, el POS no podrá emitir facturas del `document_type`
 * afectado hasta que se registre un nuevo CAI ante SAR.
 *
 * Se captura internamente por `CaiFailoverService` y se agrega al reporte como
 * entrada en `skippedNoSuccessor` — el Job orquestador (F2.5) es responsable
 * de disparar `CaiFailoverFailedNotification` (F2.4) cuando encuentra entradas
 * en ese bucket.
 *
 * No se propaga al caller: el failover está pensado como tarea de fondo no
 * interactiva, y una excepción sin captura detendría la iteración sobre otros
 * CAIs que sí podrían promoverse exitosamente.
 */
class CaiSinSucesorException extends InvoicingException
{
    public const REASON_EXPIRED = 'expired';

    public const REASON_EXHAUSTED = 'exhausted';

    public function __construct(
        public readonly int $caiRangeId,
        public readonly string $cai,
        public readonly string $documentType,
        public readonly ?int $establishmentId,
        public readonly string $reason,
    ) {
        $contexto = $establishmentId
            ? "establecimiento #{$establishmentId}"
            : 'empresa (modo centralizado)';

        $motivo = match ($reason) {
            self::REASON_EXPIRED => 'vencido',
            self::REASON_EXHAUSTED => 'agotado',
            default => $reason,
        };

        parent::__construct(
            "El CAI {$cai} (ID {$caiRangeId}, tipo {$documentType}) en {$contexto} "
            ."está {$motivo} y no existe un sucesor pre-registrado para promover. "
            .'Registre y active manualmente un nuevo CAI en Administración antes '
            .'de continuar emitiendo este tipo de documento.'
        );
    }
}

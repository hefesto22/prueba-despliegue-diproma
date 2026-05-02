<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Propósito / momento de captura de una foto de reparación.
 *
 * Permite distinguir entre las fotos del estado del equipo al recibirlo
 * (evidencia para reclamos del cliente) y las fotos posteriores
 * (diagnóstico, durante la reparación, equipo finalizado).
 *
 * Las fotos se borran automáticamente 7 días después de la entrega
 * (Job programado en F-R6) para liberar espacio en hosting compartido.
 */
enum RepairPhotoPurpose: string implements HasLabel, HasColor
{
    case Recepcion = 'recepcion';
    case Diagnostico = 'diagnostico';
    case Durante = 'durante';
    case Finalizada = 'finalizada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Recepcion => 'Al recibir',
            self::Diagnostico => 'Diagnóstico',
            self::Durante => 'Durante reparación',
            self::Finalizada => 'Equipo finalizado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Recepcion => 'gray',
            self::Diagnostico => 'info',
            self::Durante => 'warning',
            self::Finalizada => 'success',
        };
    }
}

<?php

namespace App\Services\Alerts\DTOs;

use App\Models\CaiRange;

/**
 * Resultado inmutable de un failover exitoso: par (CAI retirado, CAI promovido).
 *
 * Se genera uno por cada promoción exitosa dentro de `CaiFailoverService`. El
 * listener `LogCaiFailoverActivity` (F2.3) consume estos resultados desde el
 * evento `CaiFailoverExecuted` para persistir auditoría.
 *
 * `reason` indica cuál de las dos condiciones de failover disparó la promoción:
 *   - CaiSinSucesorException::REASON_EXPIRED    — `expiration_date < today`
 *   - CaiSinSucesorException::REASON_EXHAUSTED  — `current_number >= range_end`
 *
 * Reutilizar las constantes de la excepción mantiene una sola fuente de verdad
 * para los "motivos de failover", evitando strings paralelos que se pueden
 * desincronizar.
 */
final class CaiFailoverResult
{
    public function __construct(
        public readonly CaiRange $oldCai,
        public readonly CaiRange $newCai,
        public readonly string $reason,
    ) {}
}

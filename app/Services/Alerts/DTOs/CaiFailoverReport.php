<?php

namespace App\Services\Alerts\DTOs;

use App\Models\CaiRange;
use App\Services\Invoicing\Exceptions\CaiSinSucesorException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Resultado inmutable de una ejecución de `CaiFailoverService::executeFailover()`.
 *
 * Agrupa los tres desenlaces posibles por cada CAI procesado:
 *
 *   - `activated`          — CAIs que se promovieron exitosamente (viejo → nuevo).
 *   - `skippedNoSuccessor` — CAIs inutilizables sin sucesor disponible. El Job
 *                            orquestador dispara notificación crítica por cada uno.
 *   - `errors`             — fallos inesperados (constraint violation en carrera,
 *                            errores de DB, excepciones no tipadas del dominio).
 *                            El Job los loguea; no se convierten en notificación
 *                            para evitar ruido — un error aislado de DB no es
 *                            señal de que toda la operación esté comprometida.
 *
 * Separar los tres buckets en vez de una sola lista de "resultados mezclados"
 * permite al caller tomar decisiones claras:
 *   - ¿hubo algo en skippedNoSuccessor? → notificación crítica al admin
 *   - ¿hubo algo en errors? → alerta a oncall / log con stack trace
 *   - ¿solo activated? → todo bien, sin ruido innecesario
 */
final class CaiFailoverReport
{
    public function __construct(
        /** @var Collection<int, CaiFailoverResult> */
        public readonly Collection $activated,
        /** @var Collection<int, array{cai: CaiRange, exception: CaiSinSucesorException}> */
        public readonly Collection $skippedNoSuccessor,
        /** @var Collection<int, array{cai: CaiRange, exception: Throwable}> */
        public readonly Collection $errors,
    ) {}

    public static function empty(): self
    {
        return new self(
            activated: collect(),
            skippedNoSuccessor: collect(),
            errors: collect(),
        );
    }

    public function totalProcessed(): int
    {
        return $this->activated->count()
            + $this->skippedNoSuccessor->count()
            + $this->errors->count();
    }

    public function hasCriticalIssues(): bool
    {
        return $this->skippedNoSuccessor->isNotEmpty() || $this->errors->isNotEmpty();
    }
}

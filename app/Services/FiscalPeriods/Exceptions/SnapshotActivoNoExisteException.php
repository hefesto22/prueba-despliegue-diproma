<?php

namespace App\Services\FiscalPeriods\Exceptions;

/**
 * Se lanza cuando se intenta `redeclare()` un período fiscal que no tiene
 * ningún snapshot ACTIVO previo que se pueda marcar como supersedido.
 *
 * Por qué existe esta regla:
 *   El método `redeclare()` representa específicamente una declaración
 *   RECTIFICATIVA — su precondición es que ya exista una declaración previa
 *   activa (no supersedida) que será reemplazada. Si no existe, el flujo
 *   correcto es `declare()`, no `redeclare()`.
 *
 *   Esta separación honra CQRS-light: cada método tiene precondiciones
 *   explícitas y falla rápido (fail-fast) cuando el caller los invoca en el
 *   orden incorrecto, en vez de tener un método unificado con ramas
 *   condicionales que ocultan la intención.
 *
 * Casos típicos donde se lanza:
 *   - Período reabierto pero sin declaración inicial (caso de configuración
 *     incorrecta — se reabrió un período que nunca fue declarado).
 *   - Llamada accidental a `redeclare()` cuando el contador quería `declare()`.
 *
 * Referencia: Acuerdo SAR 189-2014 (Sección IV — Declaración Rectificativa).
 */
class SnapshotActivoNoExisteException extends FiscalPeriodException
{
    /**
     * @param  int  $periodYear   Año del período sin snapshot activo previo.
     * @param  int  $periodMonth  Mes del período sin snapshot activo previo.
     */
    public function __construct(
        public readonly int $periodYear,
        public readonly int $periodMonth,
    ) {
        $periodo = str_pad((string) $periodMonth, 2, '0', STR_PAD_LEFT) . "/{$periodYear}";

        parent::__construct(
            "No se puede emitir una declaración rectificativa para el período {$periodo} "
            . 'porque no existe una declaración activa previa que reemplazar. '
            . 'Si es la primera declaración del período, use declare() en lugar de redeclare().'
        );
    }
}

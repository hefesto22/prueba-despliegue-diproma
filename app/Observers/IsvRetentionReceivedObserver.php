<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\IsvRetentionReceived;
use App\Services\FiscalPeriods\FiscalPeriodService;

/**
 * Observer fiscal del modelo IsvRetentionReceived — garantiza la inmutabilidad
 * de las retenciones ISV cuyo período ya fue declarado al SAR.
 *
 * Por qué existe:
 *   Las retenciones ISV recibidas son insumo directo de la Sección D del
 *   Formulario 201 (créditos del período). Una vez presentada la declaración
 *   ISV del mes, el total de créditos por retenciones es lo que la SAR tiene
 *   en sus registros. Si después permitimos editar/agregar/borrar retenciones
 *   de ese período, el saldo a favor calculado por nuestro sistema deja de
 *   coincidir con lo declarado, y la próxima declaración usaría un saldo
 *   inicial incorrecto — error contable que arrastra mes tras mes.
 *
 *   Cierra la deuda simétrica con `PurchaseObserver` y `assertCanVoidInvoice`.
 *
 * Diferencia con PurchaseObserver:
 *   IsvRetentionReceived NO tiene columna de fecha — captura el período
 *   directamente en `period_year` / `period_month`. Por eso usa
 *   `assertPeriodIsOpen(year, month)` en vez de `assertDateIsInOpenPeriod(date)`.
 *
 * Reglas cubiertas:
 *   - creating: no permitir registrar retención retroactiva en período cerrado.
 *   - updating: si el nuevo período cae cerrado → bloqueo. Si el período
 *               ORIGINAL está cerrado → bloqueo (impide "mover" la retención
 *               fuera del período declarado para luego editarla).
 *   - deleting: no permitir borrar retenciones de períodos cerrados.
 *
 * Escape hatch: igual que PurchaseObserver — reopen() del período + cambio +
 * re-declare(). Sin bypass, sin flags.
 */
class IsvRetentionReceivedObserver
{
    public function __construct(
        private readonly FiscalPeriodService $periods,
    ) {}

    public function creating(IsvRetentionReceived $retention): void
    {
        if ($retention->period_year === null || $retention->period_month === null) {
            // Sin período no hay nada que verificar — el INSERT mismo fallará
            // por NOT NULL.
            return;
        }

        $this->periods->assertPeriodIsOpen(
            (int) $retention->period_year,
            (int) $retention->period_month,
            $this->describe($retention, isNewRecord: true),
        );
    }

    public function updating(IsvRetentionReceived $retention): void
    {
        // Período ORIGINAL bloqueado → no se puede tocar la retención, da igual
        // a qué nuevo período se quiera mover. Cierra el attack vector de
        // "muevo a período abierto, edito el monto, devuelvo al período cerrado".
        $originalYear  = $retention->getOriginal('period_year');
        $originalMonth = $retention->getOriginal('period_month');

        if ($originalYear !== null && $originalMonth !== null) {
            $this->periods->assertPeriodIsOpen(
                (int) $originalYear,
                (int) $originalMonth,
                $this->describe($retention),
            );
        }

        // También verifico el período nuevo si cambió: una retención no puede
        // moverse hacia un período ya declarado.
        $movedToNewPeriod = $retention->isDirty('period_year') || $retention->isDirty('period_month');

        if ($movedToNewPeriod && $retention->period_year !== null && $retention->period_month !== null) {
            $this->periods->assertPeriodIsOpen(
                (int) $retention->period_year,
                (int) $retention->period_month,
                $this->describe($retention),
            );
        }
    }

    public function deleting(IsvRetentionReceived $retention): void
    {
        if ($retention->period_year === null || $retention->period_month === null) {
            return;
        }

        $this->periods->assertPeriodIsOpen(
            (int) $retention->period_year,
            (int) $retention->period_month,
            $this->describe($retention),
        );
    }

    /**
     * Etiqueta human-readable de la retención para mensaje de error.
     * Prefiere # de constancia (legible para el contador); fallback al ID.
     */
    private function describe(IsvRetentionReceived $retention, bool $isNewRecord = false): string
    {
        $documentNumber = $retention->document_number ?? null;

        if ($documentNumber !== null && $documentNumber !== '') {
            return "la retención ISV {$documentNumber}";
        }

        if ($isNewRecord || $retention->id === null) {
            return 'esta retención ISV';
        }

        return "la retención ISV #{$retention->id}";
    }
}

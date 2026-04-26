<?php

namespace App\Services\CreditNotes\DTOs;

use App\Enums\CreditNoteReason;
use App\Models\Invoice;
use InvalidArgumentException;

/**
 * Value Object inmutable con el input completo para emitir una NC.
 *
 * Garantiza en construcción:
 *  - Al menos una línea.
 *  - Todas las líneas son LineaAcreditarInput (no arrays sueltos).
 *  - Sin líneas duplicadas sobre el mismo saleItemId (el caller debe
 *    consolidar antes de pasar al servicio — esto evita ambigüedad sobre
 *    cómo sumar duplicados y permite al servicio validar acumulativamente
 *    con reglas claras).
 *  - Si la razón exige notas (todas excepto DevolucionFisica), el texto
 *    no puede estar vacío.
 */
final class EmitirNotaCreditoInput
{
    /** @var LineaAcreditarInput[] */
    public readonly array $lineas;

    /**
     * @param  Invoice                 $invoice      Factura origen ya hidratada.
     * @param  CreditNoteReason        $reason       Razón legal de la NC.
     * @param  LineaAcreditarInput[]   $lineas       Líneas a acreditar (al menos una).
     * @param  string|null             $reasonNotes  Notas obligatorias si la razón las exige.
     */
    public function __construct(
        public readonly Invoice $invoice,
        public readonly CreditNoteReason $reason,
        array $lineas,
        public readonly ?string $reasonNotes = null,
    ) {
        if ($lineas === []) {
            throw new InvalidArgumentException(
                'Debe acreditar al menos una línea.'
            );
        }

        $vistos = [];
        foreach ($lineas as $linea) {
            if (! $linea instanceof LineaAcreditarInput) {
                throw new InvalidArgumentException(
                    'Todas las líneas deben ser instancias de LineaAcreditarInput.'
                );
            }
            if (isset($vistos[$linea->saleItemId])) {
                throw new InvalidArgumentException(
                    "sale_item_id {$linea->saleItemId} está duplicado. "
                    . 'Consolidá las líneas antes de emitir la NC.'
                );
            }
            $vistos[$linea->saleItemId] = true;
        }

        if ($reason->requiresNotes() && trim((string) $reasonNotes) === '') {
            throw new InvalidArgumentException(
                "La razón '{$reason->value}' requiere notas explicativas obligatorias."
            );
        }

        $this->lineas = array_values($lineas);
    }
}

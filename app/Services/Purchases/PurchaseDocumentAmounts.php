<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Enums\SupplierDocumentType;
use App\Services\Purchases\Exceptions\MontosDocumentoInvalidosException;

/**
 * Montos de un documento de compra, tal como vienen impresos en él.
 *
 * Desde la Fase 1 del rediseño Compras + Producto-lote (aprobado 2026-07-25)
 * una compra es un documento fiscal puro: ya no tiene líneas de producto, así
 * que sus montos no se derivan de items — se transcriben de la factura del
 * proveedor. Este Value Object es la única puerta por la que entran a
 * `purchases` (reemplaza al antiguo PurchaseTotalsCalculator):
 *
 *   - Normaliza según el tipo de documento: en Recibo Interno todo lo pagado
 *     es exento y el ISV es 0 (misma regla que SupplierDocumentType::separatesIsv).
 *   - Deriva `subtotal` y `total`, de modo que el invariante
 *     `total = gravado + exento + ISV` no se puede romper desde ningún caller.
 *   - Rechaza lo imposible (negativos, ISV sin base gravada, documento en 0)
 *     con una excepción que nombra el campo culpable.
 *   - Informa SIN bloquear cuando el ISV no cuadra con gravado × 15%.
 *
 * ─── Por qué la diferencia de ISV no bloquea ────────────────────────────────
 * Los proveedores calculan el ISV línea por línea y redondean cada una, así
 * que la suma puede diferir unos centavos de gravado × 15%. Para el Libro de
 * Compras manda lo que dice el documento impreso, no nuestro recálculo:
 * bloquear obligaría al operador a "corregir" una factura válida para que el
 * sistema la acepte. Hasta ISV_TOLERANCE se considera redondeo normal; más
 * allá, el formulario muestra un aviso para que el operador revise lo que
 * transcribió.
 */
final class PurchaseDocumentAmounts
{
    /** Diferencia (L) entre el ISV impreso y gravado × tasa que se considera redondeo normal. */
    public const ISV_TOLERANCE = 0.02;

    private function __construct(
        public readonly float $taxable,
        public readonly float $exempt,
        public readonly float $isv,
    ) {}

    /**
     * Construir los montos de un documento validando sus reglas.
     *
     * En Recibo Interno el formulario solo pide "Total pagado" (que viaja en
     * `$exempt`); si por cualquier camino llegaran también gravado o ISV, se
     * suman al total porque en un RI todo lo pagado es precio final sin
     * desglose fiscal.
     *
     * @throws MontosDocumentoInvalidosException
     */
    public static function forDocument(
        ?SupplierDocumentType $documentType,
        float $taxable,
        float $exempt,
        float $isv,
    ): self {
        foreach (['taxable_total' => $taxable, 'exempt_total' => $exempt, 'isv' => $isv] as $field => $amount) {
            if ($amount < 0) {
                throw new MontosDocumentoInvalidosException($field, 'Los montos del documento no pueden ser negativos.');
            }
        }

        // null → se trata como documento que separa ISV (Factura), igual que
        // el resto del módulo cuando el tipo todavía no está definido.
        if ($documentType !== null && ! $documentType->separatesIsv()) {
            return self::internalReceipt(round($taxable + $exempt + $isv, 2));
        }

        $taxable = round($taxable, 2);
        $exempt = round($exempt, 2);
        $isv = round($isv, 2);

        if ($taxable === 0.0 && $isv > 0) {
            throw new MontosDocumentoInvalidosException(
                'isv',
                'Hay ISV pero el importe gravado es cero. Revise la factura: el ISV sale del importe gravado.',
            );
        }

        if ($isv > $taxable) {
            throw new MontosDocumentoInvalidosException(
                'isv',
                'El ISV no puede ser mayor que el importe gravado.',
            );
        }

        if ($taxable + $exempt <= 0) {
            throw new MontosDocumentoInvalidosException(
                'taxable_total',
                'Ingrese el importe gravado o el exento del documento: no puede quedar todo en cero.',
            );
        }

        return new self($taxable, $exempt, $isv);
    }

    /**
     * Atajo para los handlers de Filament: lee tipo y montos del payload del
     * formulario, cuyas llaves coinciden con las columnas de `purchases`. Un
     * monto ausente (campo oculto en Recibo Interno) cuenta como 0.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws MontosDocumentoInvalidosException
     */
    public static function fromFormData(array $data): self
    {
        return self::forDocument(
            SupplierDocumentType::fromState($data['document_type'] ?? null),
            (float) ($data['taxable_total'] ?? 0),
            (float) ($data['exempt_total'] ?? 0),
            (float) ($data['isv'] ?? 0),
        );
    }

    /**
     * ISV esperado para una base gravada, redondeado a centavos.
     *
     * Se usa para pre-llenar el campo ISV del formulario y para detectar
     * diferencias; nunca para sobrescribir lo que dice el documento.
     */
    public static function suggestedIsv(float $taxable): float
    {
        return round(max(0.0, $taxable) * (float) config('tax.standard_rate', 0.15), 2);
    }

    public function subtotal(): float
    {
        return round($this->taxable + $this->exempt, 2);
    }

    public function total(): float
    {
        return round($this->taxable + $this->exempt + $this->isv, 2);
    }

    /**
     * ISV del documento menos el ISV esperado (positivo = el documento cobra de más).
     */
    public function isvDifference(): float
    {
        return round($this->isv - self::suggestedIsv($this->taxable), 2);
    }

    /**
     * ¿El ISV cuadra con gravado × tasa dentro de la tolerancia de redondeo?
     *
     * Compara en centavos enteros para no depender de la representación
     * binaria de los floats (0.1 + 0.2 ≠ 0.3).
     */
    public function isvMatchesRate(): bool
    {
        $differenceInCents = abs((int) round($this->isvDifference() * 100));

        return $differenceInCents <= (int) round(self::ISV_TOLERANCE * 100);
    }

    /**
     * Columnas de `purchases` listas para fill()/create().
     *
     * @return array{subtotal: float, taxable_total: float, exempt_total: float, isv: float, total: float}
     */
    public function toAttributes(): array
    {
        return [
            'subtotal' => $this->subtotal(),
            'taxable_total' => $this->taxable,
            'exempt_total' => $this->exempt,
            'isv' => $this->isv,
            'total' => $this->total(),
        ];
    }

    /**
     * @throws MontosDocumentoInvalidosException
     */
    private static function internalReceipt(float $total): self
    {
        if ($total <= 0) {
            throw new MontosDocumentoInvalidosException(
                'exempt_total',
                'El total pagado debe ser mayor que cero.',
            );
        }

        return new self(0.0, $total, 0.0);
    }
}

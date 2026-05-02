<?php

namespace App\Services\Repairs;

use App\Enums\RepairItemCondition;
use App\Enums\RepairItemSource;
use App\Enums\TaxType;
use App\Models\Product;
use App\Models\Repair;
use App\Models\RepairItem;
use App\Services\Repairs\Tax\RepairTaxableLine;
use App\Services\Repairs\Tax\RepairTaxCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Orquestador de la cotización de una reparación.
 *
 * Responsabilidades:
 *   - Resolver el `tax_type` de cada línea según su `source` y `condition`
 *     (mano_obra → exento, pieza nueva externa → gravado 15%, pieza usada
 *     externa → exento, pieza inventario → tax_type del Product).
 *   - Persistir RepairItems con sus totales pre-calculados (subtotal,
 *     isv_amount, total) — coherentes con SaleItem para que la factura
 *     final no tenga que recalcular.
 *   - Recalcular los totales del Repair padre tras cualquier alta/baja
 *     /modificación de items (subtotal, exempt_total, taxable_total, isv,
 *     total) usando RepairTaxCalculator como única fuente fiscal.
 *
 * Lo que NO hace este servicio:
 *   - Cambiar el estado del Repair (eso es RepairService en F-R3).
 *   - Cobrar anticipo / generar factura (eso es F-R3 / F-R5).
 *   - Disparar notificaciones de "items agregados" (eso lo hace el caller
 *     que tiene contexto de quién y cuándo: el Filament Action).
 *
 * Todas las operaciones de mutación se envuelven en transacción para
 * mantener atómico el "alta de item + recálculo de totales" — sin esto,
 * un fallo entre los dos pasos dejaría al Repair con totales incorrectos.
 */
class RepairQuotationService
{
    public function __construct(
        private readonly RepairTaxCalculator $calculator,
    ) {}

    /**
     * Crear un nuevo RepairItem en la cotización + recalcular totales.
     *
     * @param  array{
     *   source: RepairItemSource,
     *   product_id?: int|null,
     *   condition?: RepairItemCondition|null,
     *   description: string,
     *   external_supplier?: string|null,
     *   quantity: float,
     *   unit_cost?: float|null,
     *   unit_price: float,
     *   notes?: string|null,
     * }  $data
     */
    public function addItem(Repair $repair, array $data): RepairItem
    {
        return DB::transaction(function () use ($repair, $data) {
            $taxType = $this->resolveTaxType($data);
            $lineTotals = $this->calculateLineTotals(
                taxType: $taxType,
                unitPrice: (float) $data['unit_price'],
                quantity: (float) $data['quantity'],
            );

            $item = $repair->items()->create([
                'source' => $data['source'],
                'product_id' => $data['product_id'] ?? null,
                'condition' => $data['condition'] ?? null,
                'description' => $data['description'],
                'external_supplier' => $data['external_supplier'] ?? null,
                'quantity' => $data['quantity'],
                'unit_cost' => $data['unit_cost'] ?? null,
                'unit_price' => $data['unit_price'],
                'tax_type' => $taxType,
                'subtotal' => $lineTotals['subtotal'],
                'isv_amount' => $lineTotals['isv_amount'],
                'total' => $lineTotals['total'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->recalculateRepairTotals($repair);

            return $item->fresh();
        });
    }

    /**
     * Actualizar un RepairItem existente + recalcular totales.
     *
     * Recalcula el `tax_type` desde cero (por si cambió source o condition).
     */
    public function updateItem(RepairItem $item, array $data): RepairItem
    {
        return DB::transaction(function () use ($item, $data) {
            // Mezclar datos actuales con cambios para resolver tax_type.
            $merged = array_merge([
                'source' => $item->source,
                'condition' => $item->condition,
                'product_id' => $item->product_id,
            ], $data);

            $taxType = $this->resolveTaxType($merged);
            $lineTotals = $this->calculateLineTotals(
                taxType: $taxType,
                unitPrice: (float) ($data['unit_price'] ?? $item->unit_price),
                quantity: (float) ($data['quantity'] ?? $item->quantity),
            );

            $item->update(array_merge($data, [
                'tax_type' => $taxType,
                'subtotal' => $lineTotals['subtotal'],
                'isv_amount' => $lineTotals['isv_amount'],
                'total' => $lineTotals['total'],
            ]));

            $this->recalculateRepairTotals($item->repair);

            return $item->fresh();
        });
    }

    /**
     * Eliminar un RepairItem + recalcular totales.
     */
    public function removeItem(RepairItem $item): void
    {
        DB::transaction(function () use ($item) {
            $repair = $item->repair;
            $item->delete();
            $this->recalculateRepairTotals($repair);
        });
    }

    /**
     * Recalcular los totales del Repair desde sus items actuales.
     *
     * Idempotente: se puede llamar N veces, siempre llega al mismo resultado.
     * Llamada cada vez que un item cambia (alta/baja/modificación).
     *
     * Lee items con cursor para no cargar todo en memoria si la cotización
     * llega a tener cientos de líneas (caso edge — un repair típico tiene
     * 1-5 líneas).
     */
    public function recalculateRepairTotals(Repair $repair): Repair
    {
        $taxableLines = [];

        foreach ($repair->items()->cursor() as $item) {
            /** @var RepairItem $item */
            $taxableLines[] = new RepairTaxableLine(
                unitPrice: (float) $item->unit_price,
                quantity: (float) $item->quantity,
                taxType: $item->tax_type,
                identity: $item->id,
            );
        }

        $breakdown = $this->calculator->calculate($taxableLines);

        $repair->update([
            'subtotal' => $breakdown->subtotal,
            'exempt_total' => $breakdown->exemptTotal,
            'taxable_total' => $breakdown->taxableTotal,
            'isv' => $breakdown->isv,
            'total' => $breakdown->total,
        ]);

        return $repair->fresh();
    }

    /**
     * Resolver `TaxType` para una línea según su `source` y `condition`.
     *
     * Reglas:
     *   - HonorariosReparacion / HonorariosMantenimiento → Exento (siempre).
     *   - PiezaExterna → depende de `condition` (Nueva → Gravado / Usada → Exento).
     *   - PiezaInventario → toma del Product del catálogo.
     *
     * @throws \InvalidArgumentException si los datos no son consistentes.
     */
    private function resolveTaxType(array $data): TaxType
    {
        $source = $data['source'] instanceof RepairItemSource
            ? $data['source']
            : RepairItemSource::from($data['source']);

        if ($source->isService()) {
            return TaxType::Exento;
        }

        if ($source === RepairItemSource::PiezaExterna) {
            $condition = $data['condition'] ?? null;
            if ($condition === null) {
                throw new \InvalidArgumentException(
                    'PiezaExterna requiere condition (nueva o usada).'
                );
            }
            $condition = $condition instanceof RepairItemCondition
                ? $condition
                : RepairItemCondition::from($condition);

            return $condition->toTaxType();
        }

        if ($source === RepairItemSource::PiezaInventario) {
            $productId = $data['product_id'] ?? null;
            if (! $productId) {
                throw new \InvalidArgumentException(
                    'PiezaInventario requiere product_id.'
                );
            }
            $product = Product::findOrFail($productId);
            return $product->tax_type instanceof TaxType
                ? $product->tax_type
                : TaxType::from($product->tax_type);
        }

        // Defensa por exhaustividad — el match en RepairItemSource debería cubrir todo.
        throw new \LogicException(
            "RepairItemSource no manejado en resolveTaxType: {$source->value}"
        );
    }

    /**
     * Calcular subtotal/isv_amount/total de una línea individual.
     *
     * Misma regla que el RepairTaxCalculator pero aplicada a una sola línea
     * para persistir en `repair_items`. El cálculo del Repair padre se hace
     * agregando todas las líneas vía `recalculateRepairTotals()`.
     *
     * @return array{subtotal: float, isv_amount: float, total: float}
     */
    private function calculateLineTotals(TaxType $taxType, float $unitPrice, float $quantity): array
    {
        $multiplier = (float) config('tax.multiplier', 1.15);
        $lineTotal = round($unitPrice * $quantity, 2);

        if ($taxType === TaxType::Gravado15) {
            $subtotal = round($lineTotal / $multiplier, 2);
            $isv = round($lineTotal - $subtotal, 2);
        } else {
            $subtotal = $lineTotal;
            $isv = 0.0;
        }

        return [
            'subtotal' => $subtotal,
            'isv_amount' => $isv,
            'total' => $lineTotal,
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\RepairItemCondition;
use App\Enums\RepairItemSource;
use App\Enums\TaxType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de cotización de una Reparación.
 *
 * El `tax_type` se guarda explícito en la fila (no derivado at-runtime)
 * para que el item conserve su tipificación fiscal incluso si el Product
 * referenciado cambia su tax_type después de entregada la reparación.
 * Esto es requisito SAR para auditoría.
 *
 * Convención de precios (consistente con SaleItem):
 *   - `unit_price` siempre incluye ISV cuando la línea es gravada.
 *   - `unit_cost` es el costo interno (lo que pagó Diproma); nullable.
 */
class RepairItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'repair_id',
        'source',
        'product_id',
        'condition',
        'description',
        'external_supplier',
        'quantity',
        'unit_cost',
        'unit_price',
        'tax_type',
        'subtotal',
        'isv_amount',
        'total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'source' => RepairItemSource::class,
            'condition' => RepairItemCondition::class,
            'tax_type' => TaxType::class,
            // quantity como integer para consistencia con SaleItem y el flujo
            // fiscal de Invoice. Un cast decimal aquí causaría que `(int) 1.5`
            // en `InvoiceTotalsCalculator` redondee a 1 al emitir factura,
            // produciendo discrepancia entre Sale.total (375) e Invoice.total
            // (250) — bug fiscal directo.
            //
            // Decisión de negocio: los honorarios se cobran por "trabajo
            // realizado", no por fracciones de hora. Para cobrar el equivalente
            // a 1.5 horas, el técnico ajusta unit_price (ej: 1 × 375 en lugar
            // de 1.5 × 250). La columna BD sigue siendo decimal(8,2) por
            // compatibilidad — el cast la trata como entero.
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'isv_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function repair(): BelongsTo
    {
        return $this->belongsTo(Repair::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ─── Helpers fiscales ────────────────────────────────────

    /**
     * Precio base sin ISV (consistente con SaleItem).
     * Para gravados: divide por el multiplicador (1.15).
     */
    public function getUnitPriceBaseAttribute(): float
    {
        if ($this->tax_type === TaxType::Exento) {
            return (float) $this->unit_price;
        }

        $multiplier = (float) config('tax.multiplier', 1.15);

        return round((float) $this->unit_price / $multiplier, 2);
    }

    /** ISV unitario. */
    public function getUnitIsvAttribute(): float
    {
        return round((float) $this->unit_price - $this->unit_price_base, 2);
    }
}

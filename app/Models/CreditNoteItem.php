<?php

namespace App\Models;

use App\Enums\TaxType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de Nota de Crédito.
 *
 * Snapshot del precio y tax_type al momento de emitir la NC. Vinculada
 * a un sale_item específico de la factura origen para trazabilidad y
 * para validación acumulativa de cantidad acreditable.
 *
 * Nota sobre unit_cost: NO se guarda aquí. El servicio de NC, cuando la
 * razón es devolucion_fisica, lee el unit_cost del InventoryMovement
 * SalidaVenta original (única fuente de verdad para costos históricos).
 * Mismo patrón que SaleService::cancel().
 */
class CreditNoteItem extends Model
{
    /** @use HasFactory<\Database\Factories\CreditNoteItemFactory> */
    use HasFactory;

    protected $fillable = [
        'credit_note_id',
        'sale_item_id',
        'product_id',
        'quantity',
        'unit_price',
        'tax_type',
        'subtotal',
        'isv_amount',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity'   => 'integer',
            'unit_price' => 'decimal:2',
            'tax_type'   => TaxType::class,
            'subtotal'   => 'decimal:2',
            'isv_amount' => 'decimal:2',
            'total'      => 'decimal:2',
        ];
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ─── Helpers fiscales ────────────────────────────────────

    /**
     * Precio base (sin ISV) para artículos gravados.
     * unit_price se guarda CON ISV incluido (igual patrón que SaleItem).
     */
    public function getUnitPriceBaseAttribute(): float
    {
        if ($this->tax_type === TaxType::Exento) {
            return (float) $this->unit_price;
        }

        $multiplier = (float) config('tax.multiplier', 1.15);

        return round((float) $this->unit_price / $multiplier, 2);
    }

    public function getUnitIsvAttribute(): float
    {
        return round((float) $this->unit_price - $this->unit_price_base, 2);
    }
}

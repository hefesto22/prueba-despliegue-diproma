<?php

namespace App\Models;

use App\Enums\TaxType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
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
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'tax_type' => TaxType::class,
            'subtotal' => 'decimal:2',
            'isv_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ─── Helpers fiscales ────────────────────────────────────

    /**
     * Precio base (sin ISV) para artículos gravados.
     * El unit_price se guarda CON ISV incluido (como lo ve el cliente).
     */
    public function getUnitPriceBaseAttribute(): float
    {
        if ($this->tax_type === TaxType::Exento) {
            return (float) $this->unit_price;
        }

        $multiplier = (float) config('tax.multiplier', 1.15);

        return round((float) $this->unit_price / $multiplier, 2);
    }

    /**
     * ISV unitario.
     */
    public function getUnitIsvAttribute(): float
    {
        return round((float) $this->unit_price - $this->unit_price_base, 2);
    }
}

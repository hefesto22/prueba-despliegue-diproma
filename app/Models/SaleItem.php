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
        'description',
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
            // SaleItems desde RepairDeliveryService permiten quantity decimal
            // (ej: 1.5 horas de honorarios). Las ventas POS siguen siendo enteras.
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'tax_type' => TaxType::class,
            'subtotal' => 'decimal:2',
            'isv_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────

    /**
     * Nombre legible del item para la factura.
     *
     * Si el SaleItem tiene producto (caso POS normal): retorna `product->name`.
     * Si NO tiene producto (caso entrega de reparación): retorna `description`
     * — texto libre como "Honorarios por reparación" o "Memoria RAM externa".
     *
     * Esto encapsula la lógica para que las views de impresión no tengan
     * que verificar `$item->product_id` en cada plantilla (Law of Demeter).
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->product_id !== null) {
            return $this->product?->name ?? '—';
        }
        return $this->description ?? '—';
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

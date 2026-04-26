<?php

namespace App\Models;

use App\Enums\TaxType;
use App\Observers\PurchaseItemObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([PurchaseItemObserver::class])]
class PurchaseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'product_id',
        'quantity',
        'unit_cost',
        'tax_type',
        'subtotal',
        'isv_amount',
        'total',
        'serial_numbers',
    ];

    protected function casts(): array
    {
        return [
            'tax_type' => TaxType::class,
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'isv_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'serial_numbers' => 'array',
        ];
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ─── Helpers ─────────────────────────────────────────────

    /**
     * Costo base unitario (sin ISV).
     *
     * Deriva del `subtotal` ya persistido por PurchaseTotalsCalculator —
     * fuente única de verdad. Esto importa porque la separación de ISV
     * depende del document_type del Purchase padre (factura sí separa, RI no),
     * no solo del tax_type del item. Confiar en `subtotal/quantity` evita
     * duplicar esa regla aquí y mantiene el accessor consistente con lo que
     * se reporta y se persiste.
     */
    public function getUnitCostBaseAttribute(): float
    {
        $quantity = (int) $this->quantity;

        if ($quantity <= 0) {
            return 0.0;
        }

        return round((float) $this->subtotal / $quantity, 2);
    }

    /**
     * ISV unitario.
     *
     * Deriva de `isv_amount/quantity`. En compras tipo RI siempre es 0
     * porque el calculator no separa ISV (ver SupplierDocumentType::separatesIsv).
     */
    public function getUnitIsvAttribute(): float
    {
        $quantity = (int) $this->quantity;

        if ($quantity <= 0) {
            return 0.0;
        }

        return round((float) $this->isv_amount / $quantity, 2);
    }
}

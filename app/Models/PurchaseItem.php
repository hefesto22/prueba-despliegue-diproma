<?php

namespace App\Models;

use App\Enums\TaxType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de producto de una compra — SOLO HISTÓRICO.
 *
 * Desde la Fase 1 del rediseño Compras + Producto-lote (2026-07-25) las
 * compras son documentos fiscales sin líneas: el sistema ya no crea
 * PurchaseItems. La tabla se conserva porque las compras anteriores la usan
 * como registro de lo que entró al inventario, y PurchaseService::cancel()
 * la lee para revertir el stock de esas compras si se anulan.
 */
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
}

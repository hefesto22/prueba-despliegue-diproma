<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;

class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'establishment_id',
        'product_id',
        'type',
        'quantity',
        'unit_cost',
        'stock_before',
        'stock_after',
        'reference_type',
        'reference_id',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'stock_before' => 'integer',
            'stock_after' => 'integer',
        ];
    }

    // ─── Accessors ───────────────────────────────────────────

    /**
     * Valor total del movimiento (cantidad × costo unitario).
     * Útil para valorización de inventario sin acoplar al schema.
     */
    public function getTotalValueAttribute(): ?float
    {
        if ($this->unit_cost === null) {
            return null;
        }

        return round($this->quantity * (float) $this->unit_cost, 2);
    }

    // ─── Boot ────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (InventoryMovement $movement) {
            if (Auth::check() && is_null($movement->created_by)) {
                $movement->created_by = Auth::id();
            }
        });
    }

    // ─── Relaciones ──────────────────────────────────────────

    /**
     * Sucursal en la que ocurrió el movimiento (kardex segregado).
     * Nullable a nivel DB por backward-compatibility con datos pre-F6a.
     * F6b (InventoryService) garantiza que todo movimiento nuevo la incluya.
     */
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Referencia polimórfica al origen (Purchase, futuro Sale, etc.)
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Scopes ──────────────────────────────────────────────

    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeEntries($query)
    {
        return $query->whereIn('type', [
            MovementType::EntradaCompra,
            MovementType::EntradaAnulacionVenta,
            MovementType::AjusteEntrada,
        ]);
    }

    public function scopeExits($query)
    {
        return $query->whereIn('type', [
            MovementType::SalidaAnulacionCompra,
            MovementType::SalidaVenta,
            MovementType::AjusteSalida,
        ]);
    }

    public function scopeManual($query)
    {
        return $query->whereIn('type', [
            MovementType::AjusteEntrada,
            MovementType::AjusteSalida,
        ]);
    }

    // ─── Factory methods ─────────────────────────────────────

    /**
     * Registrar un movimiento de inventario.
     * No modifica Product.stock — eso lo hace el caller (PurchaseService, etc.)
     *
     * @param  float|null  $unitCost  Costo unitario al momento del movimiento.
     *                                 Obligatorio semánticamente para todos los movimientos
     *                                 nuevos (ver PurchaseService, SaleService). Permanece
     *                                 null solo para ajustes manuales sin costo asociado.
     * @param  Establishment|null  $establishment  Sucursal en la que ocurre el movimiento.
     *                                 Si es null, se resuelve a la matriz (backward compat
     *                                 con callers pre-F6a). F6b reemplaza este método por
     *                                 InventoryService::adjustStock que siempre inyecta la
     *                                 sucursal correcta.
     */
    public static function record(
        Product $product,
        MovementType $type,
        int $quantity,
        ?Model $reference = null,
        ?string $notes = null,
        ?float $unitCost = null,
        ?Establishment $establishment = null,
    ): static {
        $establishmentId = $establishment?->id ?? static::resolveDefaultEstablishmentId();

        return static::create([
            'establishment_id' => $establishmentId,
            'product_id' => $product->id,
            'type' => $type,
            'quantity' => $quantity,
            'unit_cost' => $unitCost !== null ? round($unitCost, 2) : null,
            'stock_before' => $product->stock,
            'stock_after' => $type->isEntry()
                ? $product->stock + $quantity
                : max(0, $product->stock - $quantity),
            'reference_type' => $reference ? get_class($reference) : null,
            'reference_id' => $reference?->id,
            'notes' => $notes,
        ]);
    }

    /**
     * Resuelve el ID de la matriz para fallback en callers pre-F6a.
     *
     * No cacheamos a nivel de clase porque provoca flakiness entre tests
     * con RefreshDatabase: el ID cacheado queda apuntando a un registro
     * ya borrado y el siguiente insert falla con FK violation.
     *
     * En producción este path solo se ejecuta para el Filament manual de
     * ajustes (un movimiento por acción del usuario) y desaparece cuando
     * F6a.5 inyecta la sucursal activa del user autenticado. El costo de
     * una query extra por movimiento sin establishment es negligible.
     */
    protected static function resolveDefaultEstablishmentId(): ?int
    {
        return Establishment::main()->value('id');
    }
}

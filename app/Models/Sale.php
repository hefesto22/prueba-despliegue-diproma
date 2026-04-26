<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Sale extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields, LogsActivity;

    protected $fillable = [
        'sale_number',
        'establishment_id',
        'customer_id',
        'customer_name',
        'customer_rtn',
        'date',
        'status',
        'payment_method',
        'discount_type',
        'discount_value',
        'discount_amount',
        'subtotal',
        'isv',
        'total',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => SaleStatus::class,
            'payment_method' => PaymentMethod::class,
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'isv' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    // ─── Boot ────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Sale $sale) {
            if (empty($sale->sale_number)) {
                $sale->sale_number = static::generateNextNumber();
            }

            if (empty($sale->date)) {
                $sale->date = now();
            }
        });
    }

    /**
     * Generar número correlativo: VTA-2026-00001
     */
    public static function generateNextNumber(): string
    {
        $year = now()->year;
        $prefix = "VTA-{$year}-";

        $lastNumber = static::withTrashed()
            ->where('sale_number', 'like', "{$prefix}%")
            ->orderByDesc('sale_number')
            ->value('sale_number');

        if ($lastNumber) {
            $sequence = (int) substr($lastNumber, -5) + 1;
        } else {
            $sequence = 1;
        }

        return $prefix . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    // ─── Activity Log ────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sale_number', 'customer_name', 'status', 'total'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Venta {$eventName}");
    }

    // ─── Relaciones ──────────────────────────────────────────

    /**
     * Sucursal donde se emitió la venta (multi-sucursal).
     * Nullable a nivel DB para backward-compatibility con datos pre-F6a,
     * pero toda venta nueva debe tener establecimiento (invariante en SaleService).
     */
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    /**
     * Cliente registrado (nullable — consumidor final no tiene).
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    // ─── Scopes ──────────────────────────────────────────────

    public function scopeCompletadas($query)
    {
        return $query->where('status', SaleStatus::Completada);
    }

    public function scopePendientes($query)
    {
        return $query->where('status', SaleStatus::Pendiente);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('date', today());
    }

    // ─── Helpers ─────────────────────────────────────────────

    /**
     * ¿Es consumidor final? (sin RTN)
     */
    public function isConsumidorFinal(): bool
    {
        return empty($this->customer_rtn);
    }

    /**
     * ¿Tiene descuento aplicado?
     */
    public function hasDiscount(): bool
    {
        return $this->discount_amount > 0;
    }

    /**
     * Nombre de display del cliente (con "Consumidor Final" si no hay RTN).
     */
    public function getClientDisplayAttribute(): string
    {
        $name = $this->customer_name ?: 'Consumidor Final';

        if ($this->customer_rtn) {
            return "{$name} (RTN: {$this->customer_rtn})";
        }

        return $name;
    }
}

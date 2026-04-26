<?php

namespace App\Models;

use App\Enums\ProductCondition;
use App\Enums\ProductType;
use App\Enums\TaxType;
use App\Traits\HasAuditFields;
use App\Models\SpecOption;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Product extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields, LogsActivity;

    protected $fillable = [
        'name',
        'slug',
        'sku',
        'description',
        'category_id',
        'product_type',
        'brand',
        'model',
        'condition',
        'tax_type',
        'cost_price',
        'sale_price',
        'stock',
        'min_stock',
        'specs',
        'serial_numbers',
        'image_path',
        'is_active',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
            'condition' => ProductCondition::class,
            'tax_type' => TaxType::class,
            'cost_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'stock' => 'integer',
            'min_stock' => 'integer',
            'specs' => 'array',
            'serial_numbers' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // ─── Boot ────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            static::enforceTaxType($product);
            static::autoGenerateName($product);
            static::autoGenerateSku($product);
            static::persistCustomSpecOptions($product);

            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });

        static::updating(function (Product $product) {
            static::enforceTaxType($product);
            static::autoGenerateName($product);
            static::persistCustomSpecOptions($product);

            if ($product->isDirty('name') && !$product->isDirty('slug')) {
                $product->slug = Str::slug($product->name);
            }
        });
    }

    private static function enforceTaxType(Product $product): void
    {
        $product->tax_type = $product->condition === ProductCondition::Used
            ? TaxType::Exento
            : TaxType::Gravado15;
    }

    /**
     * Autogenerar el nombre basado en tipo + marca + modelo + key specs.
     * Ej: "Laptop HP ProBook 450 - i7-1355U / 16 GB / 512 GB SSD"
     */
    private static function autoGenerateName(Product $product): void
    {
        if ($product->product_type) {
            $product->name = $product->product_type->generateName(
                $product->brand ?? '',
                $product->model ?? '',
                $product->specs ?? []
            );
        }
    }

    /**
     * Autogenerar SKU: TIPO-MAR-00001
     * Ej: LAP-HP-00001, CON-SON-00002, ACC-LOG-00003
     */
    private static function autoGenerateSku(Product $product): void
    {
        if (filled($product->sku)) {
            return; // Si ya tiene SKU (edición), no regenerar
        }

        $typePrefix = $product->product_type?->skuPrefix() ?? 'GEN';

        // Primeras 3 letras de la marca (o GEN si no hay marca)
        $brandPrefix = filled($product->brand)
            ? strtoupper(Str::substr(Str::ascii($product->brand), 0, 3))
            : 'GEN';

        // Siguiente correlativo para este prefijo
        $prefix = "{$typePrefix}-{$brandPrefix}-";
        $lastSku = static::withTrashed()
            ->where('sku', 'like', "{$prefix}%")
            ->orderByDesc('sku')
            ->value('sku');

        if ($lastSku) {
            $lastNumber = (int) Str::afterLast($lastSku, '-');
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        $product->sku = $prefix . str_pad((string) $nextNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Guardar valores personalizados de specs en spec_options
     * para que estén disponibles como opciones la próxima vez.
     */
    private static function persistCustomSpecOptions(Product $product): void
    {
        $type = $product->product_type;
        $specs = $product->specs ?? [];

        if (! $type || empty($specs)) {
            return;
        }

        $selectKeys = collect($type->specFields())
            ->where('type', 'select')
            ->pluck('key')
            ->toArray();

        foreach ($selectKeys as $key) {
            $value = $specs[$key] ?? null;
            if (filled($value)) {
                SpecOption::ensureExists($key, $value);
            }
        }
    }

    // ─── Activity Log ────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name', 'sku', 'category_id', 'product_type', 'condition',
                'tax_type', 'cost_price', 'sale_price', 'stock', 'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Producto {$eventName}");
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class)->orderByDesc('created_at');
    }

    // ─── Scopes ──────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock', '<=', 'min_stock')
            ->where('stock', '>', 0);
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('stock', '<=', 0);
    }

    public function scopeByCondition($query, ProductCondition $condition)
    {
        return $query->where('condition', $condition);
    }

    public function scopeByType($query, ProductType $type)
    {
        return $query->where('product_type', $type);
    }

    // ─── Helpers fiscales ────────────────────────────────────

    public function calculateSaleIsv(): float
    {
        return round($this->sale_price * $this->tax_type->rate(), 2);
    }

    public function calculateCostIsv(): float
    {
        return round($this->cost_price * $this->tax_type->rate(), 2);
    }

    public function getSalePriceWithIsvAttribute(): float
    {
        return round($this->sale_price + $this->calculateSaleIsv(), 2);
    }

    public function getCostPriceWithIsvAttribute(): float
    {
        return round($this->cost_price + $this->calculateCostIsv(), 2);
    }

    public function getProfitMarginAttribute(): float
    {
        if ($this->cost_price <= 0) {
            return 0;
        }
        return round((($this->sale_price - $this->cost_price) / $this->cost_price) * 100, 2);
    }

    public function getProfitAmountAttribute(): float
    {
        return round($this->sale_price - $this->cost_price, 2);
    }

    /**
     * Convertir precio con ISV a precio base.
     * Usa el multiplicador centralizado en config/tax.php.
     */
    public static function priceWithoutIsv(float $priceWithIsv): float
    {
        return round($priceWithIsv / config('tax.multiplier', 1.15), 2);
    }

    /**
     * Convertir precio base a precio con ISV.
     * Usa el multiplicador centralizado en config/tax.php.
     */
    public static function priceWithIsv(float $basePrice): float
    {
        return round($basePrice * config('tax.multiplier', 1.15), 2);
    }

    public function isLowStock(): bool
    {
        return $this->stock <= $this->min_stock && $this->stock > 0;
    }

    public function isOutOfStock(): bool
    {
        return $this->stock <= 0;
    }
}

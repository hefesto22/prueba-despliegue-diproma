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
        'is_service',
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
            // product_type queda como string libre (sin cast a enum) para que
            // el cliente pueda agregar tipos personalizados desde el form
            // estilo "(PERSONALIZADO)", igual que RAM o procesador. El accessor
            // `productTypeEnum` resuelve el enum si el valor coincide con uno
            // de los 8 cases conocidos.
            'condition' => ProductCondition::class,
            'tax_type' => TaxType::class,
            'cost_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'stock' => 'integer',
            'min_stock' => 'integer',
            'specs' => 'array',
            'serial_numbers' => 'array',
            'is_active' => 'boolean',
            // is_service: true para honorarios y otros servicios sin inventario
            // (no se descuenta stock al vender, precio editable en POS).
            // false para productos físicos (stock real, descuento al vender).
            'is_service' => 'boolean',
        ];
    }

    /**
     * Resolver el ProductType enum desde el string almacenado, si coincide
     * con uno de los 8 cases conocidos. Retorna null para tipos personalizados
     * (ej. "EQUIPO DE SEGURIDAD", "HONORARIOS") — el caller debe manejar el
     * fallback (generación de nombre/SKU simple sin specs específicos).
     *
     * Case-insensitive: el valor en DB está en MAYÚSCULAS (mismo formato que
     * spec_options usa), pero los enum cases están en minúsculas.
     */
    public function getProductTypeEnumAttribute(): ?ProductType
    {
        if (! filled($this->product_type)) {
            return null;
        }

        return ProductType::tryFrom(mb_strtolower((string) $this->product_type));
    }

    // ─── Boot ────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            static::normalizeProductType($product);
            static::enforceTaxType($product);
            static::autoGenerateName($product);
            static::autoGenerateSku($product);
            static::persistCustomSpecOptions($product);

            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });

        static::updating(function (Product $product) {
            static::normalizeProductType($product);
            static::enforceTaxType($product);
            static::autoGenerateName($product);
            static::persistCustomSpecOptions($product);

            if ($product->isDirty('name') && !$product->isDirty('slug')) {
                $product->slug = Str::slug($product->name);
            }
        });
    }

    /**
     * Normalizar product_type según si es enum case conocido o custom:
     *   - Enum case (laptop, desktop, etc.) → guardar en MINÚSCULAS para
     *     compatibilidad con el code que compara `$selected === $type->value`.
     *   - Custom (Equipo de seguridad, Honorarios) → guardar en MAYÚSCULAS,
     *     mismo formato que spec_options usa.
     *
     * Esto evita duplicados ("Laptop" vs "LAPTOP" como si fueran tipos
     * distintos) y mantiene el código existente del form funcionando con
     * los specs dinámicos por enum.
     */
    private static function normalizeProductType(Product $product): void
    {
        if (! filled($product->product_type)) {
            return;
        }

        $value = (string) $product->product_type;

        // Si es un case del enum (case-insensitive), guardar como su value oficial.
        $enum = ProductType::tryFrom(mb_strtolower($value));
        if ($enum) {
            $product->product_type = $enum->value; // ej: 'laptop', 'desktop'
            return;
        }

        // Custom: MAYÚSCULAS para consistencia con spec_options.
        $product->product_type = mb_strtoupper($value);
    }

    /**
     * Tax type según condición — SOLO aplica para tipos enum (Laptop,
     * Desktop, etc.) donde la condición Nuevo/Usado tiene sentido fiscal.
     *
     * Para tipos CUSTOM (Honorarios, Equipo de seguridad, etc.) el cliente
     * elige el tax_type explícitamente desde el form (Exento por default
     * para servicios; Gravado 15% si es mercancía). Respetamos esa decisión.
     *
     * Honduras Art. 15 inc e Decreto 194-2002: bienes usados son exentos
     * de ISV. Servicios profesionales también son exentos por la misma ley
     * pero por una causal distinta — por eso lo marca el cliente.
     */
    private static function enforceTaxType(Product $product): void
    {
        // Tipos CUSTOM: respetar tax_type del form (no sobreescribir).
        if (filled($product->product_type) && ! $product->productTypeEnum) {
            // Si el form no envió tax_type, default a Exento (caso típico
            // de servicios profesionales como Honorarios).
            if (! $product->tax_type) {
                $product->tax_type = TaxType::Exento;
            }
            return;
        }

        // Tipos ENUM: regla automática por condición.
        $product->tax_type = $product->condition === ProductCondition::Used
            ? TaxType::Exento
            : TaxType::Gravado15;
    }

    /**
     * Autogenerar el nombre basado en tipo + marca + modelo + key specs.
     *
     * Para tipos enum conocidos (Laptop, Desktop, etc.): usa el método del
     * enum que sabe qué specs son "key" para mostrar en el nombre.
     * Para tipos personalizados (ej. "EQUIPO DE SEGURIDAD", "HONORARIOS"):
     * incluye el subtype de specs si existe, para distinguir entre
     * "HONORARIOS - INSTALACIÓN" y "HONORARIOS - MANTENIMIENTO".
     */
    private static function autoGenerateName(Product $product): void
    {
        if (! filled($product->product_type)) {
            return;
        }

        $enum = $product->productTypeEnum;

        if ($enum) {
            // Tipo enum conocido: delegar al enum (incluye key specs).
            $product->name = $enum->generateName(
                $product->brand ?? '',
                $product->model ?? '',
                $product->specs ?? []
            );

            return;
        }

        // Tipo personalizado: tipo + marca + modelo + subtype.
        $parts = [mb_strtoupper((string) $product->product_type)];
        if (filled($product->brand)) $parts[] = mb_strtoupper((string) $product->brand);
        if (filled($product->model)) $parts[] = mb_strtoupper((string) $product->model);

        $base = implode(' ', $parts);

        // Lectura DEFENSIVA del subtype: el cast 'array' puede no haber
        // aplicado todavía durante el evento creating/updating, dependiendo
        // del orden de hidratación de Eloquent. Probamos varios accesos.
        $specs = $product->specs;

        // Si el cast no aplicó: leemos raw attributes (puede ser string JSON).
        if (! is_array($specs)) {
            $rawSpecs = $product->getAttributes()['specs'] ?? null;
            if (is_string($rawSpecs)) {
                $specs = json_decode($rawSpecs, true) ?: [];
            } else {
                $specs = is_array($rawSpecs) ? $rawSpecs : [];
            }
        }

        $subtype = $specs['subtype'] ?? null;

        if (filled($subtype)) {
            $base .= ' - ' . mb_strtoupper((string) $subtype);
        }

        $product->name = $base;
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

        // Para tipos enum: usar su skuPrefix oficial (LAP, DES, etc.).
        // Para tipos personalizados: primeras 3 letras del tipo en MAYÚSCULA
        // (ej. "EQUIPO DE SEGURIDAD" → "EQU"). Esto da SKUs legibles para
        // tipos nuevos sin que el dev tenga que tocar nada.
        $typePrefix = $product->productTypeEnum?->skuPrefix()
            ?? static::derivePrefixFromCustomType($product->product_type)
            ?? 'GEN';

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
     * Guardar valores personalizados de specs en spec_options para que
     * estén disponibles como opciones la próxima vez.
     *
     * Para `product_type`: SOLO se persiste si es CUSTOM (no enum). Los enum
     * cases (laptop, desktop, etc.) ya vienen del enum en el dropdown — no
     * necesitan duplicarse en spec_options.
     */
    private static function persistCustomSpecOptions(Product $product): void
    {
        // Persistir tipo SOLO si es custom (no es uno de los 8 enum cases).
        // Si es enum, el dropdown ya lo provee desde el enum directamente.
        if (filled($product->product_type) && ! $product->productTypeEnum) {
            SpecOption::ensureExists('product_type', (string) $product->product_type);
        }

        // Specs específicos: solo aplicable a tipos enum conocidos
        // (los tipos custom no tienen schema de specs definido).
        $enum = $product->productTypeEnum;
        $specs = $product->specs ?? [];

        if (! $enum || empty($specs)) {
            return;
        }

        $selectKeys = collect($enum->specFields())
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

    /**
     * Derivar prefijo SKU desde un tipo personalizado (no enum).
     * Toma las primeras 3 letras del string limpiado (sin acentos ni
     * caracteres especiales). Retorna null si el resultado está vacío.
     */
    private static function derivePrefixFromCustomType(?string $type): ?string
    {
        if (! filled($type)) {
            return null;
        }

        $clean = strtoupper(Str::ascii((string) $type));
        $clean = preg_replace('/[^A-Z]/', '', $clean) ?: '';

        return $clean !== '' ? Str::substr($clean, 0, 3) : null;
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

    /**
     * Productos con stock bajo (stock <= min_stock pero > 0).
     *
     * Excluye servicios: su stock es virtual (999999) y nunca debería
     * aparecer en alertas de stock bajo. Sin este filtro, el badge del
     * sidebar y los reportes incluirían honorarios cuando no aplica.
     */
    public function scopeLowStock($query)
    {
        return $query->where('is_service', false)
            ->whereColumn('stock', '<=', 'min_stock')
            ->where('stock', '>', 0);
    }

    /**
     * Productos sin stock (stock <= 0).
     *
     * Excluye servicios — su stock virtual nunca llega a 0.
     */
    public function scopeOutOfStock($query)
    {
        return $query->where('is_service', false)
            ->where('stock', '<=', 0);
    }

    public function scopeByCondition($query, ProductCondition $condition)
    {
        return $query->where('condition', $condition);
    }

    public function scopeByType($query, ProductType|string $type)
    {
        // Acepta enum (legacy) o string (custom). Internamente normaliza
        // a string ya que la columna es VARCHAR.
        $value = $type instanceof ProductType ? $type->value : $type;
        return $query->where('product_type', $value);
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

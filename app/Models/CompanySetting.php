<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class CompanySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'legal_name',
        'trade_name',
        'rtn',
        'business_type',
        'address',
        'city',
        'department',
        'municipality',
        'phone',
        'phone_secondary',
        'email',
        'website',
        'logo_path',
        'tax_regime',
        'fiscal_period_start',
        'cai_expiration_warning_days',
        'cai_exhaustion_warning_percentage',
        'cai_exhaustion_warning_absolute',
        'cash_discrepancy_tolerance',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_period_start' => 'immutable_date',
            'cai_expiration_warning_days' => 'array',
            'cai_exhaustion_warning_percentage' => 'decimal:2',
            'cai_exhaustion_warning_absolute' => 'integer',
            'cash_discrepancy_tolerance' => 'decimal:2',
        ];
    }

    // ─── Defaults de umbrales CAI ────────────────────────
    //
    // Si por cualquier motivo la configuración está vacía (nueva empresa,
    // columnas sin backfill, etc.), estos defaults garantizan que las
    // alertas sigan funcionando con valores razonables. El admin puede
    // sobrescribirlos desde el panel de CompanySettings.

    public const DEFAULT_CAI_EXPIRATION_WARNING_DAYS = [30, 15, 7];

    public const DEFAULT_CAI_EXHAUSTION_WARNING_PERCENTAGE = 10.00;

    public const DEFAULT_CAI_EXHAUSTION_WARNING_ABSOLUTE = 100;

    /**
     * Default de tolerancia de descuadre de caja en lempiras. Fallback si el
     * campo está en null (no configurado).
     */
    public const DEFAULT_CASH_DISCREPANCY_TOLERANCE = 50.00;

    /**
     * Días de aviso para vencimiento de CAI. Fallback a los defaults si está vacío.
     *
     * @return array<int, int>
     */
    public function getCaiExpirationWarningDaysListAttribute(): array
    {
        $raw = $this->cai_expiration_warning_days;

        if (! is_array($raw) || empty($raw)) {
            return self::DEFAULT_CAI_EXPIRATION_WARNING_DAYS;
        }

        // Normalizar: solo enteros positivos, únicos, ordenados desc (más pronto primero).
        return collect($raw)
            ->map(fn ($v) => (int) $v)
            ->filter(fn (int $v) => $v > 0)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    public function getCaiExhaustionPercentageThresholdAttribute(): float
    {
        $value = (float) $this->cai_exhaustion_warning_percentage;

        return $value > 0 ? $value : self::DEFAULT_CAI_EXHAUSTION_WARNING_PERCENTAGE;
    }

    public function getCaiExhaustionAbsoluteThresholdAttribute(): int
    {
        $value = (int) $this->cai_exhaustion_warning_absolute;

        return $value > 0 ? $value : self::DEFAULT_CAI_EXHAUSTION_WARNING_ABSOLUTE;
    }

    /**
     * Tolerancia efectiva de descuadre de caja (en lempiras).
     *
     * Null en la configuración → fallback al default del sistema. Negativo
     * (no debería ocurrir, pero defensa en profundidad) → 0.
     *
     * Método separado (no accessor) para no chocar con el cast de la columna
     * — Filament lee/escribe `cash_discrepancy_tolerance` directamente, y el
     * resto del sistema consulta `effectiveCashDiscrepancyTolerance()`.
     */
    public function effectiveCashDiscrepancyTolerance(): float
    {
        $raw = $this->attributes['cash_discrepancy_tolerance'] ?? null;

        if ($raw === null) {
            return self::DEFAULT_CASH_DISCREPANCY_TOLERANCE;
        }

        $value = (float) $raw;

        return $value >= 0 ? $value : 0.0;
    }

    // ─── Relaciones ──────────────────────────────────────

    public function establishments(): HasMany
    {
        return $this->hasMany(Establishment::class);
    }

    /**
     * Establecimiento matriz (único por empresa).
     * Devuelve el primero activo y marcado como matriz.
     */
    public function mainEstablishment(): ?Establishment
    {
        return $this->establishments()
            ->where('is_main', true)
            ->where('is_active', true)
            ->first();
    }

    // ─── Singleton Pattern ──────────────────────────────

    /**
     * Obtener la configuración de la empresa (singleton cacheado).
     *
     * Se cachea por 24 horas para evitar queries repetitivas.
     * El cache se invalida automáticamente al guardar.
     */
    public static function current(): static
    {
        return Cache::remember('company_settings', 60 * 60 * 24, function () {
            return static::firstOrCreate(
                ['id' => 1],
                [
                    'legal_name' => 'Mi Empresa',
                    'rtn' => '0000-0000-00000',
                    'address' => 'Dirección pendiente de configurar',
                ]
            );
        });
    }

    /**
     * Limpiar caché al guardar cambios.
     */
    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('company_settings'));
    }

    // ─── Helpers ─────────────────────────────────────────

    /**
     * Nombre a mostrar: comercial si existe, legal si no.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->trade_name ?: $this->legal_name;
    }

    /**
     * RTN formateado: XXXX-XXXX-XXXXX
     */
    public function getFormattedRtnAttribute(): string
    {
        $clean = preg_replace('/\D/', '', $this->rtn);

        if (strlen($clean) === 14) {
            return substr($clean, 0, 4) . '-' . substr($clean, 4, 4) . '-' . substr($clean, 8, 6);
        }

        return $this->rtn;
    }

    /**
     * Dirección completa formateada.
     */
    public function getFullAddressAttribute(): string
    {
        return collect([
            $this->address,
            $this->municipality,
            $this->city,
            $this->department,
        ])->filter()->implode(', ');
    }

    /**
     * Prefijo fiscal SAR por defecto (del establecimiento matriz).
     * Formato: XXX-XXX-XX. Si no hay matriz configurada, retorna placeholder.
     *
     * Uso en formularios de CAI: prellenar el prefijo desde aquí.
     * Para sistemas por sucursal, el resolvedor de correlativo tomará el prefijo
     * del Establishment vinculado al CaiRange, no de este accessor.
     */
    public function getInvoicePrefixAttribute(): string
    {
        $main = $this->mainEstablishment();

        return $main
            ? $main->fullPrefix('01')
            : '001-001-01';
    }

    /**
     * Verificar si los datos mínimos están configurados.
     */
    public function isConfigured(): bool
    {
        return filled($this->legal_name)
            && $this->legal_name !== 'Mi Empresa'
            && filled($this->rtn)
            && $this->rtn !== '0000-0000-00000'
            && filled($this->address)
            && $this->address !== 'Dirección pendiente de configurar';
    }
}

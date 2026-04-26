<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class CaiRange extends Model
{
    use HasFactory;

    protected $fillable = [
        'cai',
        'authorization_date',
        'expiration_date',
        'document_type',
        'establishment_id',
        'prefix',
        'range_start',
        'range_end',
        'current_number',
        'is_active',
    ];

    /**
     * `active_lookup` es columna generada STORED en DB (ver migración
     * 2026_04_18_080001_add_unique_active_cai_constraint_to_cai_ranges).
     * No aparece en $fillable porque MySQL la calcula — Eloquent debe
     * considerarla solo de lectura.
     */
    protected $guarded = ['active_lookup'];

    protected function casts(): array
    {
        return [
            'authorization_date' => 'date',
            'expiration_date' => 'date',
            'range_start' => 'integer',
            'range_end' => 'integer',
            'current_number' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // ─── Relaciones ──────────────────────────────────────

    /**
     * Establecimiento al que pertenece este CAI (nullable = CAI central).
     */
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    // ─── Scopes ──────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForInvoices($query)
    {
        return $query->where('document_type', '01');
    }

    public function scopeNotExpired($query)
    {
        return $query->where('expiration_date', '>=', now()->toDateString());
    }

    // ─── Helpers ─────────────────────────────────────────

    /**
     * Facturas restantes en este rango.
     */
    public function getRemainingAttribute(): int
    {
        return max(0, $this->range_end - $this->current_number);
    }

    /**
     * Porcentaje de uso del rango.
     */
    public function getUsagePercentageAttribute(): float
    {
        $total = $this->range_end - $this->range_start + 1;

        if ($total <= 0) {
            return 100;
        }

        $used = $this->current_number - $this->range_start + 1;

        return round(($used / $total) * 100, 1);
    }

    /**
     * Rango formateado para mostrar.
     * Ej: "001-001-01-00000001 a 001-001-01-00000500"
     */
    public function getFormattedRangeAttribute(): string
    {
        $start = $this->prefix . '-' . str_pad((string) $this->range_start, 8, '0', STR_PAD_LEFT);
        $end = $this->prefix . '-' . str_pad((string) $this->range_end, 8, '0', STR_PAD_LEFT);

        return "{$start} a {$end}";
    }

    /**
     * Porcentaje restante del rango (complemento de usage_percentage).
     *
     * Lo exponemos como atributo para que los checkers de alerta no recalculen
     * la aritmética desde `remaining` y `range_end - range_start`.
     */
    public function getRemainingPercentageAttribute(): float
    {
        $total = $this->range_end - $this->range_start + 1;

        if ($total <= 0) {
            return 0.0;
        }

        return round(($this->remaining / $total) * 100, 2);
    }

    /**
     * ¿El rango está cerca de agotarse?
     *
     * Antes este método tenía el umbral `15` hardcoded. Ahora lee los
     * umbrales configurables de CompanySettings:
     *
     *   - `cai_exhaustion_percentage_threshold` (default 10%)
     *   - `cai_exhaustion_absolute_threshold`   (default 100 facturas)
     *
     * Dispara cuando se cumple LO QUE PRIMERO ocurra entre los dos
     * criterios — así una empresa con rango pequeño no pierde la alerta
     * por quedarse esperando el 10% del rango grande.
     *
     * Retorna false si ya está agotado (otro helper cubre ese caso).
     */
    public function isNearExhaustion(): bool
    {
        if ($this->remaining <= 0) {
            return false;
        }

        $settings = CompanySetting::current();

        $absoluteThreshold = $settings->cai_exhaustion_absolute_threshold;
        $percentageThreshold = $settings->cai_exhaustion_percentage_threshold;

        return $this->remaining <= $absoluteThreshold
            || $this->remaining_percentage <= $percentageThreshold;
    }

    /**
     * Verificar si está agotado.
     */
    public function isExhausted(): bool
    {
        return $this->current_number >= $this->range_end;
    }

    /**
     * Verificar si está vencido.
     */
    public function isExpired(): bool
    {
        return $this->expiration_date->isPast();
    }

    /**
     * Días restantes antes del vencimiento.
     */
    public function getDaysUntilExpirationAttribute(): int
    {
        return max(0, (int) now()->diffInDays($this->expiration_date, false));
    }

    // ─── Activación ──────────────────────────────────────

    /**
     * Activar este CAI y desactivar los demás equivalentes en alcance.
     *
     * "Equivalentes en alcance" significa: mismo `document_type` Y mismo
     * `establishment_id` (incluyendo el caso `null` → CAI centralizado).
     *
     * Esta versión respeta la arquitectura multi-sucursal: activar el CAI
     * de la matriz NO desactiva los activos de otras sucursales. La versión
     * anterior desactivaba globalmente por `document_type` y rompía esa
     * dimensión.
     *
     * El `lockForUpdate` en la búsqueda garantiza que dos activaciones
     * simultáneas del mismo alcance no produzcan dos activos. La constraint
     * DB `uniq_active_cai_per_doc_estab` es la red de seguridad adicional:
     * si algún código futuro olvida pasar por este método, MySQL lo rechaza.
     */
    public function activate(): void
    {
        DB::transaction(function () {
            // Desactivar los del mismo alcance (doc + establishment).
            // `whereNull`/`where` preserva el caso de establishment_id null
            // (CAI centralizado): un centralizado solo colisiona con otro
            // centralizado del mismo tipo, no con uno de sucursal.
            $query = static::query()
                ->where('document_type', $this->document_type)
                ->where('id', '!=', $this->id)
                ->where('is_active', true);

            if ($this->establishment_id === null) {
                $query->whereNull('establishment_id');
            } else {
                $query->where('establishment_id', $this->establishment_id);
            }

            $query->lockForUpdate()->update(['is_active' => false]);

            $this->update(['is_active' => true]);
        });
    }
}

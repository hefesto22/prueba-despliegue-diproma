<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Establecimiento del Obligado Tributario (Acuerdo 481-2017, Art. 3).
 *
 * Un RTN (empresa) puede tener N establecimientos. Cada uno tiene su código
 * SAR propio y puede asociarse a rangos CAI específicos (multi-sucursal).
 */
class Establishment extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_setting_id',
        'code',
        'emission_point',
        'name',
        'type',
        'address',
        'city',
        'department',
        'municipality',
        'phone',
        'is_main',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ─── Relaciones ──────────────────────────────────────

    public function companySetting(): BelongsTo
    {
        return $this->belongsTo(CompanySetting::class);
    }

    public function caiRanges(): HasMany
    {
        return $this->hasMany(CaiRange::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // ─── Scopes ──────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeMain(Builder $query): Builder
    {
        return $query->where('is_main', true);
    }

    // ─── Accessors ───────────────────────────────────────

    /**
     * Prefijo fiscal para numeración SAR.
     * Formato: XXX-XXX (establecimiento-punto). El tipo de documento
     * se agrega desde el CaiRange al armar el número completo.
     */
    public function getPrefixAttribute(): string
    {
        return "{$this->code}-{$this->emission_point}";
    }

    /**
     * Prefijo completo incluyendo tipo de documento (requiere pasar el tipo).
     * Uso: $establishment->fullPrefix('01') → "001-001-01"
     */
    public function fullPrefix(string $documentType = '01'): string
    {
        return "{$this->code}-{$this->emission_point}-{$documentType}";
    }

    // ─── Unicidad de "matriz" ────────────────────────────

    /**
     * Al guardar un establecimiento como matriz, desmarcar los demás de la misma empresa.
     */
    protected static function booted(): void
    {
        static::saving(function (self $establishment) {
            if ($establishment->is_main && $establishment->company_setting_id) {
                static::where('company_setting_id', $establishment->company_setting_id)
                    ->where('id', '!=', $establishment->id)
                    ->where('is_main', true)
                    ->update(['is_main' => false]);
            }
        });
    }
}

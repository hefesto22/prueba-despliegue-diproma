<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Período fiscal mensual para control de anulabilidad de facturas.
 *
 * Ciclo de vida de un período:
 *   1. Abierto       → declared_at = NULL, reopened_at = NULL
 *   2. Declarado     → declared_at != NULL (cerrado al SAR)
 *   3. Reabierto     → reopened_at != NULL y reopened_at > declared_at (declaración rectificativa)
 *   4. Re-declarado  → declared_at se actualiza > reopened_at (cerrado de nuevo)
 *
 * Reglas clave:
 *   - isOpen() cubre tanto "nunca declarado" como "reabierto pendiente de re-declarar".
 *   - Unicidad por (period_year, period_month) garantiza un único registro por mes.
 *   - Factura se puede anular solo si período.isOpen() == true.
 *
 * La resolución "¿a qué período pertenece esta factura?" vive en FiscalPeriodService,
 * no aquí, porque depende del invoice_date y de policy de creación lazy.
 */
class FiscalPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'period_year',
        'period_month',
        'declared_at',
        'declared_by',
        'declaration_notes',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'declared_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
        ];
    }

    // ─── Relaciones ──────────────────────────────────────

    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    // ─── Scopes ──────────────────────────────────────────

    /**
     * Períodos abiertos: nunca declarados o reabiertos pendientes de re-declarar.
     *
     * Un período está abierto si:
     *   - declared_at IS NULL, o
     *   - reopened_at IS NOT NULL y reopened_at > declared_at
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('declared_at')
                ->orWhereColumn('reopened_at', '>', 'declared_at');
        });
    }

    /**
     * Períodos cerrados al SAR (declarados y no reabiertos después).
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereNotNull('declared_at')
            ->where(function (Builder $q) {
                $q->whereNull('reopened_at')
                    ->orWhereColumn('reopened_at', '<=', 'declared_at');
            });
    }

    /**
     * Filtrar por año y mes (el orden natural de búsqueda del sistema).
     */
    public function scopeForMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->where('period_year', $year)
            ->where('period_month', $month);
    }

    // ─── Estado del período ──────────────────────────────

    /**
     * ¿El período admite anulación de facturas?
     * True cuando nunca se declaró, o se reabrió y aún no se re-declaró.
     */
    public function isOpen(): bool
    {
        if ($this->declared_at === null) {
            return true;
        }

        return $this->reopened_at !== null
            && $this->reopened_at->greaterThan($this->declared_at);
    }

    /**
     * ¿El período está cerrado al SAR (declaración vigente)?
     */
    public function isClosed(): bool
    {
        return ! $this->isOpen();
    }

    /**
     * ¿Fue reabierto alguna vez?
     * Útil para mostrar badge "reabierto" en UI aunque ya se haya re-declarado.
     */
    public function wasReopened(): bool
    {
        return $this->reopened_at !== null;
    }

    // ─── Accessors ───────────────────────────────────────

    /**
     * Etiqueta humana del período. Ej: "Abril 2026".
     */
    public function getPeriodLabelAttribute(): string
    {
        return CarbonImmutable::create($this->period_year, $this->period_month, 1)
            ->locale('es')
            ->translatedFormat('F Y');
    }

    /**
     * Primer día del mes del período (para comparaciones con invoice_date).
     */
    public function getPeriodStartAttribute(): CarbonImmutable
    {
        return CarbonImmutable::create($this->period_year, $this->period_month, 1)
            ->startOfDay();
    }

    /**
     * Último día del mes del período.
     */
    public function getPeriodEndAttribute(): CarbonImmutable
    {
        return CarbonImmutable::create($this->period_year, $this->period_month, 1)
            ->endOfMonth();
    }
}

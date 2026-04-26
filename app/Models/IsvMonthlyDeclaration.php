<?php

namespace App\Models;

use App\Observers\IsvMonthlyDeclarationObserver;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Snapshot inmutable de la declaración ISV mensual presentada al SAR.
 *
 * Una fila = una "foto" exacta de los totales del Formulario 201 en el momento
 * de presentarlo al portal SIISAR. Ver migración
 * `2026_04_19_131000_create_isv_monthly_declarations_table` para el contexto
 * completo del diseño.
 *
 * Lo que NO vive en este modelo (SRP):
 *   - Ciclo de vida open/declared/reopened → vive en `FiscalPeriod`.
 *   - Lógica de cálculo de totales → vive en `IsvMonthlyDeclarationService`.
 *   - Creación + marcado de `superseded_at` → vive en
 *     `IsvMonthlyDeclarationService::createFromFiscalPeriod()` dentro de una
 *     transacción con `lockForUpdate`.
 *   - Validación de inmutabilidad fiscal post-insert → vive en el Observer.
 *
 * Lo que SÍ vive aquí:
 *   - Relación con FiscalPeriod y con los users que firmaron la presentación.
 *   - Casts numéricos exactos (decimal:2) — cuadratura SAR no tolera floats.
 *   - Scopes de consulta (active, forPeriod) para Filament Resource y queries.
 *   - Helpers de estado (isActive, isSuperseded) — azúcar sobre
 *     `superseded_at` que los callers usarán tanto como las condiciones raw.
 *
 * Sin SoftDeletes: los snapshots son permanentes por diseño, el concepto de
 * "borrado" en este dominio es `superseded_at != null`.
 */
#[ObservedBy([IsvMonthlyDeclarationObserver::class])]
class IsvMonthlyDeclaration extends Model
{
    use HasFactory, HasAuditFields, LogsActivity;

    protected $fillable = [
        'fiscal_period_id',
        'declared_at',
        'declared_by_user_id',
        'siisar_acuse_number',

        // Sección A — Ventas
        'ventas_gravadas',
        'ventas_exentas',
        'ventas_totales',

        // Sección B — Compras
        'compras_gravadas',
        'compras_exentas',
        'compras_totales',

        // Cálculo ISV
        'isv_debito_fiscal',
        'isv_credito_fiscal',
        'isv_retenciones_recibidas',
        'saldo_a_favor_anterior',
        'isv_a_pagar',
        'saldo_a_favor_siguiente',

        'notes',

        // Ciclo de reemplazo (rectificativa)
        'superseded_at',
        'superseded_by_user_id',

        // Auditoría
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'declared_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',

            // Sección A — Ventas
            'ventas_gravadas' => 'decimal:2',
            'ventas_exentas' => 'decimal:2',
            'ventas_totales' => 'decimal:2',

            // Sección B — Compras
            'compras_gravadas' => 'decimal:2',
            'compras_exentas' => 'decimal:2',
            'compras_totales' => 'decimal:2',

            // Cálculo ISV
            'isv_debito_fiscal' => 'decimal:2',
            'isv_credito_fiscal' => 'decimal:2',
            'isv_retenciones_recibidas' => 'decimal:2',
            'saldo_a_favor_anterior' => 'decimal:2',
            'isv_a_pagar' => 'decimal:2',
            'saldo_a_favor_siguiente' => 'decimal:2',

            // `is_active` es VIRTUAL en DB (CASE WHEN superseded_at IS NULL THEN 1 ELSE NULL END).
            // MySQL la reporta como tinyint(1)/NULL; el cast a 'boolean' la
            // normaliza pero hay que acordarse de que en el caso supersedido
            // llega como NULL, no FALSE — usar los helpers isActive()/isSuperseded().
            'is_active' => 'boolean',
        ];
    }

    // ─── Activity Log ────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'fiscal_period_id',
                'declared_at',
                'declared_by_user_id',
                'siisar_acuse_number',
                'ventas_totales',
                'compras_totales',
                'isv_debito_fiscal',
                'isv_credito_fiscal',
                'isv_retenciones_recibidas',
                'saldo_a_favor_anterior',
                'isv_a_pagar',
                'saldo_a_favor_siguiente',
                'superseded_at',
                'superseded_by_user_id',
                'notes',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Declaración ISV {$eventName}");
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    /**
     * Usuario (contador) que firmó la presentación al SAR de este snapshot.
     */
    public function declaredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by_user_id');
    }

    /**
     * Usuario que activó la rectificativa que reemplazó a este snapshot.
     * Null si el snapshot está activo.
     */
    public function supersededByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superseded_by_user_id');
    }

    // ─── Scopes ──────────────────────────────────────────────

    /**
     * Snapshots vigentes (no reemplazados por una rectificativa posterior).
     *
     * Usar `whereNull('superseded_at')` directamente en vez de
     * `where('is_active', 1)` porque `is_active` es virtual y algunos
     * motores/versiones de MySQL no optimizan igual sobre columnas generadas
     * que sobre columnas físicas. El UNIQUE sigue cubriendo la garantía.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    /**
     * Snapshots supersedidos (histórico de rectificativas anteriores).
     */
    public function scopeSuperseded(Builder $query): Builder
    {
        return $query->whereNotNull('superseded_at');
    }

    /**
     * Todos los snapshots (activo + supersedidos) de un período fiscal.
     * Para el histórico de rectificativas en Filament.
     */
    public function scopeForFiscalPeriod(Builder $query, int $fiscalPeriodId): Builder
    {
        return $query->where('fiscal_period_id', $fiscalPeriodId);
    }

    /**
     * Filtrar por año/mes vía el período fiscal relacionado. Útil cuando el
     * caller tiene (year, month) pero no el `fiscal_period_id`.
     */
    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->whereHas('fiscalPeriod', function (Builder $q) use ($year, $month) {
            $q->where('period_year', $year)
                ->where('period_month', $month);
        });
    }

    // ─── Helpers de estado ───────────────────────────────────

    /**
     * ¿Es el snapshot vigente del período? True cuando no fue supersedido.
     */
    public function isActive(): bool
    {
        return $this->superseded_at === null;
    }

    /**
     * ¿Fue reemplazado por una rectificativa posterior?
     */
    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }
}

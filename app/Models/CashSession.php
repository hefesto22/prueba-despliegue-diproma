<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Sesión de caja — período entre apertura y cierre de una caja física.
 *
 * Estado derivado de `closed_at`:
 *   - closed_at IS NULL  → sesión abierta (operativa)
 *   - closed_at IS NOT NULL → sesión cerrada (histórica, inmutable)
 *
 * No se usa SoftDeletes porque las sesiones cerradas son registros fiscales/
 * operativos — eliminarlas (aún soft) borra trazabilidad de cuadre histórico.
 *
 * @property int $id
 * @property int $establishment_id
 * @property int $opened_by_user_id
 * @property \Illuminate\Support\Carbon $opened_at
 * @property string $opening_amount
 * @property int|null $closed_by_user_id
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property string|null $expected_closing_amount
 * @property string|null $actual_closing_amount
 * @property string|null $discrepancy
 * @property int|null $authorized_by_user_id
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $closed_by_system_at
 * @property bool $requires_reconciliation
 */
class CashSession extends Model
{
    use HasFactory, HasAuditFields, LogsActivity;

    protected $fillable = [
        'establishment_id',
        'opened_by_user_id',
        'opened_at',
        'opening_amount',
        'closed_by_user_id',
        'closed_at',
        'closed_by_system_at',
        'requires_reconciliation',
        'expected_closing_amount',
        'actual_closing_amount',
        'discrepancy',
        'authorized_by_user_id',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'closed_by_system_at' => 'datetime',
            'requires_reconciliation' => 'boolean',
            'opening_amount' => 'decimal:2',
            'expected_closing_amount' => 'decimal:2',
            'actual_closing_amount' => 'decimal:2',
            'discrepancy' => 'decimal:2',
        ];
    }

    // ─── Activity Log ────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'establishment_id',
                'opened_by_user_id',
                'opening_amount',
                'closed_by_user_id',
                'closed_at',
                'closed_by_system_at',
                'requires_reconciliation',
                'expected_closing_amount',
                'actual_closing_amount',
                'discrepancy',
                'authorized_by_user_id',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Sesión de caja {$eventName}");
    }

    // ─── Estado ──────────────────────────────────────────────

    /**
     * ¿La sesión está abierta? (closed_at IS NULL)
     *
     * Única fuente de verdad — nunca consultar un campo boolean redundante.
     */
    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function isClosed(): bool
    {
        return ! $this->isOpen();
    }

    /**
     * ¿La sesión fue cerrada automáticamente por el sistema (no por humano)?
     *
     * Se usa para distinguir cierres manuales (cajero apretó "Cerrar mi caja")
     * de cierres del job AutoCloseCashSessionsJob. Los auto-cerrados tienen
     * actual_closing_amount=null y requires_reconciliation=true hasta que
     * un humano los reconcilie con el conteo físico real.
     */
    public function wasClosedBySystem(): bool
    {
        return $this->closed_by_system_at !== null;
    }

    /**
     * ¿La sesión espera conciliación (conteo físico posterior al auto-cierre)?
     *
     * Cuando el job auto-cierra una sesión, el sistema no contó plata. Esta
     * bandera indica al admin/cajero que debe completar el cierre con el
     * conteo real para cerrar el ciclo de auditoría. Se desmarca al ejecutar
     * CashSessionService::reconcile().
     */
    public function isPendingReconciliation(): bool
    {
        return $this->requires_reconciliation === true;
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    // ─── Scopes ──────────────────────────────────────────────

    /**
     * Solo sesiones abiertas (en operación).
     *
     * @param  Builder<CashSession>  $query
     * @return Builder<CashSession>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    /**
     * Solo sesiones cerradas (históricas).
     *
     * @param  Builder<CashSession>  $query
     * @return Builder<CashSession>
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereNotNull('closed_at');
    }

    /**
     * Filtrar por sucursal.
     *
     * @param  Builder<CashSession>  $query
     * @return Builder<CashSession>
     */
    public function scopeForEstablishment(Builder $query, int $establishmentId): Builder
    {
        return $query->where('establishment_id', $establishmentId);
    }

    /**
     * Solo sesiones auto-cerradas pendientes de conciliación.
     *
     * Se usa para el dashboard de admin ("hay X sesiones por conciliar"),
     * para el bloqueo de apertura en CashSessionService::open() y para el
     * filtro del listado.
     *
     * @param  Builder<CashSession>  $query
     * @return Builder<CashSession>
     */
    public function scopePendingReconciliation(Builder $query): Builder
    {
        return $query->where('requires_reconciliation', true);
    }
}

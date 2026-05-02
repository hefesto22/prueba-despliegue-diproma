<?php

namespace App\Models;

use App\Enums\CustomerCreditSource;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Saldo a favor del cliente.
 *
 * Surge cuando un anticipo de reparación queda sin uso (cliente rechazó la
 * cotización o se anuló la reparación) y el cliente prefirió convertir el
 * monto en crédito en vez de devolución en efectivo.
 *
 * Reglas inmutables:
 *   - `amount` (crédito original) NUNCA se modifica.
 *   - `balance` solo se decrementa con `lockForUpdate` para prevenir
 *     race conditions en uso concurrente del crédito.
 *   - Cuando `balance == 0`, se sella `fully_used_at`.
 *
 * No es un documento fiscal — los CreditNote (Notas de Crédito SAR tipo 03)
 * acreditan facturas ya emitidas; CustomerCredit existe ANTES de cualquier
 * factura.
 */
class CustomerCredit extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields, LogsActivity;

    protected $fillable = [
        'customer_id',
        'source_type',
        'source_repair_id',
        'establishment_id',
        'amount',
        'balance',
        'expires_at',
        'fully_used_at',
        'description',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => CustomerCreditSource::class,
            'amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'expires_at' => 'datetime',
            'fully_used_at' => 'datetime',
        ];
    }

    // ─── Activity Log ────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['customer_id', 'source_type', 'amount', 'balance', 'fully_used_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => "Crédito de cliente {$event}");
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sourceRepair(): BelongsTo
    {
        return $this->belongsTo(Repair::class, 'source_repair_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    // ─── Scopes ──────────────────────────────────────────────

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('balance', '>', 0)
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeOfCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    // ─── Helpers ─────────────────────────────────────────────

    public function isAvailable(): bool
    {
        return (float) $this->balance > 0
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isFullyUsed(): bool
    {
        return $this->fully_used_at !== null;
    }
}

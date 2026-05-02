<?php

namespace App\Models;

use App\Enums\RepairStatus;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Reparación — orden de servicio técnico.
 *
 * Las reglas de negocio (transiciones, cobro de anticipo, entrega) viven
 * en RepairService. Este modelo solo expone relaciones, casts, scopes y
 * accessors. NO contiene lógica de negocio.
 *
 * @property int                 $id
 * @property string              $repair_number
 * @property string              $qr_token
 * @property RepairStatus        $status
 * @property string              $customer_name
 * @property string              $customer_phone
 * @property string|null         $customer_rtn
 * @property string              $device_brand
 * @property string|null         $device_model
 * @property string              $reported_issue
 * @property string|null         $diagnosis
 * @property string|null         $device_password
 * @property \Illuminate\Support\Carbon $received_at
 * @property string              $subtotal
 * @property string              $exempt_total
 * @property string              $taxable_total
 * @property string              $isv
 * @property string              $total
 * @property string              $advance_payment
 */
class Repair extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields, LogsActivity;

    protected $fillable = [
        'repair_number',
        'qr_token',
        'establishment_id',
        'customer_id',
        'customer_name',
        'customer_phone',
        'customer_rtn',
        'device_category_id',
        'device_brand',
        'device_model',
        'device_serial',
        'device_password',
        'reported_issue',
        'diagnosis',
        'status',
        'technician_id',
        'received_at',
        'quoted_at',
        'approved_at',
        'rejected_at',
        'repair_started_at',
        'completed_at',
        'delivered_at',
        'abandoned_at',
        'cancelled_at',
        'subtotal',
        'exempt_total',
        'taxable_total',
        'isv',
        'total',
        'advance_payment',
        'sale_id',
        'invoice_id',
        'customer_credit_id',
        'notes',
        'internal_notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => RepairStatus::class,
            // device_password se cifra con la APP_KEY de Laravel.
            'device_password' => 'encrypted',
            'received_at' => 'datetime',
            'quoted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'repair_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'abandoned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'exempt_total' => 'decimal:2',
            'taxable_total' => 'decimal:2',
            'isv' => 'decimal:2',
            'total' => 'decimal:2',
            'advance_payment' => 'decimal:2',
        ];
    }

    // ─── Boot ────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Repair $repair) {
            if (empty($repair->repair_number)) {
                $repair->repair_number = static::generateNextNumber();
            }
            if (empty($repair->qr_token)) {
                $repair->qr_token = (string) Str::uuid();
            }
            if (empty($repair->received_at)) {
                $repair->received_at = now();
            }
            if (empty($repair->status)) {
                $repair->status = RepairStatus::Recibido;
            }
        });
    }

    /**
     * Generar número correlativo: REP-2026-00001
     *
     * Usa `withTrashed()` para no reciclar números de reparaciones soft-deleted.
     * El correlativo es continuo por año natural.
     */
    public static function generateNextNumber(): string
    {
        $year = now()->year;
        $prefix = "REP-{$year}-";

        $last = static::withTrashed()
            ->where('repair_number', 'like', "{$prefix}%")
            ->orderByDesc('repair_number')
            ->value('repair_number');

        $sequence = $last ? ((int) substr($last, -5) + 1) : 1;

        return $prefix . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    // ─── Activity Log ────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'repair_number',
                'status',
                'customer_name',
                'technician_id',
                'total',
                'advance_payment',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => "Reparación {$event}");
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deviceCategory(): BelongsTo
    {
        return $this->belongsTo(DeviceCategory::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RepairItem::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RepairPhoto::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(RepairStatusLog::class)->orderByDesc('created_at');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customerCredit(): BelongsTo
    {
        return $this->belongsTo(CustomerCredit::class);
    }

    // ─── Scopes ──────────────────────────────────────────────

    public function scopeOfStatus(Builder $query, RepairStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            RepairStatus::Entregada->value,
            RepairStatus::Rechazada->value,
            RepairStatus::Abandonada->value,
            RepairStatus::Anulada->value,
        ]);
    }

    public function scopeReadyForDelivery(Builder $query): Builder
    {
        return $query->where('status', RepairStatus::ListoEntrega->value);
    }

    public function scopeOfTechnician(Builder $query, int $userId): Builder
    {
        return $query->where('technician_id', $userId);
    }

    public function scopePhotosCleanupCandidates(Builder $query, int $daysAfterDelivery = 7): Builder
    {
        return $query->where('status', RepairStatus::Entregada->value)
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '<=', now()->subDays($daysAfterDelivery))
            ->whereHas('photos');
    }

    public function scopeAbandonmentCandidates(Builder $query, int $daysSinceReady = 60): Builder
    {
        return $query->where('status', RepairStatus::ListoEntrega->value)
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', now()->subDays($daysSinceReady));
    }

    // ─── Helpers ─────────────────────────────────────────────

    /**
     * Saldo pendiente al cobrar (total - anticipo aplicado).
     * En entrega, este es el monto que la factura cobra realmente.
     */
    public function getOutstandingAmountAttribute(): float
    {
        return round((float) $this->total - (float) $this->advance_payment, 2);
    }

    /** ¿Tiene anticipo cobrado? */
    public function hasAdvancePayment(): bool
    {
        return (float) $this->advance_payment > 0;
    }

    /**
     * ¿El anticipo cobrado está pendiente de resolución (devolver o convertir)?
     *
     * Retorna true sólo cuando:
     *   - hay anticipo cobrado (>0),
     *   - el repair está en estado terminal,
     *   - NO se convirtió en crédito (customer_credit_id null),
     *   - NO se registró un AdvanceRefunded en su bitácora.
     *
     * Usado por las Filament Actions para decidir si mostrar
     * "Devolver anticipo" / "Convertir en crédito".
     */
    public function hasUnresolvedAdvance(): bool
    {
        if (! $this->hasAdvancePayment()) {
            return false;
        }
        if (! $this->status->isTerminal()) {
            return false;
        }
        if ($this->customer_credit_id !== null) {
            return false;
        }
        return ! $this->statusLogs()
            ->where('event_type', \App\Enums\RepairLogEvent::AdvanceRefunded->value)
            ->exists();
    }

    /** URL pública firmada para que el cliente consulte estado por QR. */
    public function publicUrl(): string
    {
        return route('repairs.public.show', ['token' => $this->qr_token]);
    }
}

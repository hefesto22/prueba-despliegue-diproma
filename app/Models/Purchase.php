<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Observers\PurchaseObserver;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[ObservedBy([PurchaseObserver::class])]
class Purchase extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields, LogsActivity;

    protected $fillable = [
        'purchase_number',
        'establishment_id',
        'supplier_invoice_number',
        'supplier_cai',
        'document_type',
        'supplier_id',
        'date',
        'due_date',
        'status',
        'payment_status',
        'subtotal',
        'taxable_total',
        'exempt_total',
        'isv',
        'total',
        'credit_days',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'status' => PurchaseStatus::class,
            'payment_status' => PaymentStatus::class,
            'document_type' => SupplierDocumentType::class,
            'subtotal' => 'decimal:2',
            'taxable_total' => 'decimal:2',
            'exempt_total' => 'decimal:2',
            'isv' => 'decimal:2',
            'total' => 'decimal:2',
            'credit_days' => 'integer',
        ];
    }

    // ─── Boot ────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Purchase $purchase) {
            if (empty($purchase->purchase_number)) {
                $purchase->purchase_number = static::generateNextNumber();
            }

            // Calcular due_date si hay crédito (la lógica de crédito está
            // pausada hasta que se implemente el módulo de Cuentas por Pagar,
            // pero el cálculo del due_date queda como base para cuando se
            // active — ver SupplierDocumentType y notas en PurchaseForm).
            if ($purchase->credit_days > 0 && $purchase->date && ! $purchase->due_date) {
                $purchase->due_date = $purchase->date->addDays($purchase->credit_days);
            }

            // payment_status: NO se marca Pagada en `creating` aunque sea contado.
            // Razón: una compra recién creada está en estado Borrador — todavía
            // no afectó stock, no actualizó costo promedio, no es operación
            // ejecutada. Decir "Pagada" en Borrador es prematuro y contradictorio
            // (¿pagada de qué, si la compra no se ha ejecutado?).
            //
            // La transición a Pagada para contado vive ahora en
            // PurchaseService::confirm() — junto con la actualización de stock
            // y costo promedio, dentro de la misma transacción. Coherente con
            // el dominio: el pago se ejecuta al recibir la mercancía, no al
            // armar el borrador.
        });
    }

    /**
     * Generar número correlativo: COMP-2026-00001
     */
    public static function generateNextNumber(): string
    {
        $year = now()->year;
        $prefix = "COMP-{$year}-";

        $lastNumber = static::withTrashed()
            ->where('purchase_number', 'like', "{$prefix}%")
            ->orderByDesc('purchase_number')
            ->value('purchase_number');

        if ($lastNumber) {
            $sequence = (int) substr($lastNumber, -5) + 1;
        } else {
            $sequence = 1;
        }

        return $prefix . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    // ─── Activity Log ────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'purchase_number',
                'supplier_invoice_number',
                'supplier_cai',
                'document_type',
                'supplier_id',
                'status',
                'payment_status',
                'total',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Compra {$eventName}");
    }

    // ─── Relaciones ──────────────────────────────────────────

    /**
     * Sucursal a la que pertenece la compra (define a qué bodega entra el stock).
     * Nullable a nivel DB por backward-compatibility con datos pre-F6a;
     * toda compra nueva debe tener establecimiento (invariante en PurchaseService).
     */
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    // ─── Scopes ──────────────────────────────────────────────

    public function scopeStatus($query, PurchaseStatus $status)
    {
        return $query->where('status', $status);
    }

    public function scopeBorradores($query)
    {
        return $query->where('status', PurchaseStatus::Borrador);
    }

    public function scopeConfirmadas($query)
    {
        return $query->where('status', PurchaseStatus::Confirmada);
    }

    /**
     * Compras con pago realmente pendiente: solo las de crédito confirmadas
     * que aún no están pagadas. Las de contado no entran nunca aquí —
     * en contado el pago se ejecuta al momento y se registra como Pagada
     * automáticamente en el hook creating().
     */
    public function scopePendientesPago($query)
    {
        return $query->where('payment_status', '!=', PaymentStatus::Pagada)
            ->where('credit_days', '>', 0)
            ->where('status', PurchaseStatus::Confirmada);
    }

    public function scopeVencidas($query)
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->where('payment_status', '!=', PaymentStatus::Pagada)
            ->where('status', PurchaseStatus::Confirmada);
    }

    // ─── Helpers ─────────────────────────────────────────────

    /**
     * ¿Se puede editar esta compra?
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * ¿Está vencida?
     */
    public function isOverdue(): bool
    {
        return $this->due_date
            && $this->due_date->isPast()
            && $this->payment_status !== PaymentStatus::Pagada
            && $this->status === PurchaseStatus::Confirmada;
    }

    /**
     * Días hasta vencimiento (negativo = vencida).
     */
    public function getDaysUntilDueAttribute(): ?int
    {
        if (! $this->due_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->due_date, false);
    }

}

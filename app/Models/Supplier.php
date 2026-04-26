<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Supplier extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields, LogsActivity;

    protected $fillable = [
        'name',
        'rtn',
        'company_name',
        'contact_name',
        'email',
        'phone',
        'phone_secondary',
        'address',
        'city',
        'department',
        'credit_days',
        'notes',
        'is_active',
        'is_generic',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'credit_days' => 'integer',
            'is_active' => 'boolean',
            'is_generic' => 'boolean',
        ];
    }

    /**
     * Nombre canónico del proveedor genérico usado por el flujo de Recibo Interno.
     * Se consulta vía {@see static::forInternalReceipts()}. No cambiar sin una
     * migración de datos — los recibos históricos referencian este registro por id.
     */
    public const GENERIC_RI_NAME = 'Varios / Sin identificar';

    // ─── Activity Log ────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'rtn', 'email', 'phone', 'credit_days', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Proveedor {$eventName}");
    }

    // ─── Relaciones ──────────────────────────────────────────

    /**
     * Compras realizadas a este proveedor.
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    // ─── Scopes ──────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWithCredit($query)
    {
        return $query->where('credit_days', '>', 0);
    }

    /**
     * Proveedores genéricos del sistema (no editables/eliminables como operativos).
     * Hoy cubre solo "Varios / Sin identificar" para Recibos Internos; queda abierto
     * a futuros genéricos (empleados/caja chica, gastos varios, etc.).
     */
    public function scopeGeneric($query)
    {
        return $query->where('is_generic', true);
    }

    /**
     * Proveedores reales (excluye los genéricos del sistema). Úselo en listados
     * donde el operador no debería ver o seleccionar genéricos manualmente —
     * el genérico de RI se asigna automáticamente desde el flujo de Purchase.
     */
    public function scopeOperational($query)
    {
        return $query->where('is_generic', false);
    }

    // ─── Helpers ─────────────────────────────────────────────

    /**
     * Proveedor genérico usado automáticamente por el flujo de Recibo Interno.
     *
     * Este registro lo inserta la migración 2026_04_19_add_recibo_interno_support_to_suppliers
     * — no debe crearse ni eliminarse manualmente. Si no existe (entorno mal
     * migrado) lanza ModelNotFoundException: fail-fast es correcto, porque sin
     * este registro el flujo de RI no puede funcionar.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public static function forInternalReceipts(): self
    {
        return static::query()
            ->generic()
            ->where('name', self::GENERIC_RI_NAME)
            ->firstOrFail();
    }

    /**
     * ¿Este proveedor es un genérico del sistema? (no eliminable, no editable
     * como operativo normal). Conveniencia para Policies y UI.
     */
    public function isGeneric(): bool
    {
        return (bool) $this->is_generic;
    }

    /**
     * Nombre para mostrar: nombre comercial + razón social si difiere.
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->company_name && $this->company_name !== $this->name) {
            return "{$this->name} ({$this->company_name})";
        }

        return $this->name;
    }

    /**
     * RTN formateado: 0801-1999-12345
     */
    public function getFormattedRtnAttribute(): string
    {
        $rtn = preg_replace('/\D/', '', $this->rtn);

        if (strlen($rtn) === 14) {
            return substr($rtn, 0, 4) . '-' . substr($rtn, 4, 4) . '-' . substr($rtn, 8, 6);
        }

        return $this->rtn;
    }

    /**
     * ¿El proveedor ofrece crédito?
     */
    public function hasCredit(): bool
    {
        return $this->credit_days > 0;
    }
}

<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Customer extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields, LogsActivity;

    protected $fillable = [
        'name',
        'rtn',
        'phone',
        'email',
        'address',
        'notes',
        'is_active',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ─── Activity Log ────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'rtn', 'phone', 'email', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Cliente {$eventName}");
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    // ─── Scopes ──────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWithRtn($query)
    {
        return $query->whereNotNull('rtn')->where('rtn', '!=', '');
    }

    // ─── Helpers ─────────────────────────────────────────────

    /**
     * RTN formateado: 0801-1999-12345
     */
    public function getFormattedRtnAttribute(): ?string
    {
        if (empty($this->rtn)) {
            return null;
        }

        $rtn = preg_replace('/\D/', '', $this->rtn);

        if (strlen($rtn) === 14) {
            return substr($rtn, 0, 4) . '-' . substr($rtn, 4, 4) . '-' . substr($rtn, 8, 6);
        }

        return $this->rtn;
    }

    /**
     * ¿Es consumidor final? (sin RTN registrado)
     */
    public function isConsumidorFinal(): bool
    {
        return empty($this->rtn);
    }
}

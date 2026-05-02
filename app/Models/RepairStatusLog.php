<?php

namespace App\Models;

use App\Enums\RepairLogEvent;
use App\Enums\RepairStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora WORM de eventos de una Reparación.
 *
 * Inmutable: solo INSERT, jamás UPDATE o DELETE.
 * Por eso `$timestamps = false` y solo manejamos `created_at` (con DB default).
 *
 * Cada evento describe quién hizo qué, cuándo, y con qué metadata.
 * RepairService es el único caller que crea filas aquí.
 */
class RepairStatusLog extends Model
{
    use HasFactory;

    /**
     * Esta tabla solo tiene `created_at` (sin `updated_at`).
     * Reforzamos la inmutabilidad: si Eloquent intentara escribir
     * `updated_at`, la migración no tiene esa columna y rompería.
     */
    public $timestamps = false;

    protected $fillable = [
        'repair_id',
        'event_type',
        'from_status',
        'to_status',
        'changed_by',
        'metadata',
        'note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => RepairLogEvent::class,
            'from_status' => RepairStatus::class,
            'to_status' => RepairStatus::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ─── Boot ────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            if (empty($log->created_at)) {
                $log->created_at = now();
            }
        });

        // Defensa adicional: bloquear update y delete a nivel de modelo.
        // Si algún caller intenta hacerlo, se lanza excepción en runtime.
        static::updating(function () {
            throw new \LogicException('RepairStatusLog es inmutable: no se puede actualizar.');
        });
        static::deleting(function () {
            throw new \LogicException('RepairStatusLog es inmutable: no se puede eliminar.');
        });
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function repair(): BelongsTo
    {
        return $this->belongsTo(Repair::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

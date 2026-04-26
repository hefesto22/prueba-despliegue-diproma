<?php

namespace App\Models;

use App\Enums\IsvRetentionType;
use App\Observers\IsvRetentionReceivedObserver;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Retención de ISV recibida (sujeto pasivo retenido).
 *
 * Una fila representa una retención que un tercero le hizo a Diproma sobre
 * una operación gravada — el tercero declara y entera el ISV, Diproma usa
 * el monto como crédito en su Formulario 201 mensual.
 *
 * Ver migración 2026_04_19_130000_create_isv_retentions_received_table para
 * el detalle de los 3 tipos soportados.
 *
 * Lógica de negocio fuera del modelo (SRP): el agregado por período vive en
 * IsvMonthlyDeclarationService::sumRetentionsByType(...). El modelo solo expone
 * relaciones, casts y scopes de filtrado.
 */
#[ObservedBy([IsvRetentionReceivedObserver::class])]
class IsvRetentionReceived extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields, LogsActivity;

    /**
     * Nombre explícito: Laravel pluraliza solo la última palabra
     * (`isv_retention_receiveds`), pero semánticamente "received" es un
     * adjetivo en singular que modifica a "retentions". Mantener el nombre
     * correcto al costo de esta única línea es más limpio que aceptar el
     * default gramaticalmente incorrecto.
     */
    protected $table = 'isv_retentions_received';

    protected $fillable = [
        'establishment_id',
        'period_year',
        'period_month',
        'retention_type',
        'agent_rtn',
        'agent_name',
        'document_number',
        'document_path',
        'amount',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'retention_type' => IsvRetentionType::class,
            'amount' => 'decimal:2',
        ];
    }

    // ─── Activity Log ────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'establishment_id',
                'period_year',
                'period_month',
                'retention_type',
                'agent_rtn',
                'agent_name',
                'document_number',
                'amount',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Retención ISV {$eventName}");
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    // ─── Scopes ──────────────────────────────────────────────

    /**
     * Filtra retenciones de un período mensual concreto.
     */
    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query
            ->where('period_year', $year)
            ->where('period_month', $month);
    }

    /**
     * Filtra por tipo (enum o string del enum).
     */
    public function scopeOfType(Builder $query, IsvRetentionType|string $type): Builder
    {
        $value = $type instanceof IsvRetentionType ? $type->value : $type;
        return $query->where('retention_type', $value);
    }

    /**
     * Filtra por sucursal. Pasar null para "sin sucursal asignada".
     */
    public function scopeForEstablishment(Builder $query, ?int $establishmentId): Builder
    {
        return $establishmentId === null
            ? $query->whereNull('establishment_id')
            : $query->where('establishment_id', $establishmentId);
    }

    // ─── Helpers ─────────────────────────────────────────────

    /**
     * Etiqueta "Abril 2026" para UI.
     */
    public function periodLabel(): string
    {
        $meses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        return ($meses[$this->period_month] ?? (string) $this->period_month) . ' ' . $this->period_year;
    }
}

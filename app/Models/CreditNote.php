<?php

namespace App\Models;

use App\Concerns\LocksFiscalFieldsAfterEmission;
use App\Enums\CreditNoteReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nota de Crédito (SAR tipo '03').
 *
 * Documento fiscal que acredita total o parcialmente una factura origen.
 * Se emite inmutable: una vez `emitted_at != null`, los campos fiscales no
 * pueden actualizarse — el único cambio permitido es anular (`is_void=true`).
 *
 * Los totales se guardan en positivo; el efecto de crédito es implícito por
 * el tipo de documento. El campo `integrity_hash` es el identificador público
 * para la URL de verificación con QR.
 */
class CreditNote extends Model
{
    /** @use HasFactory<\Database\Factories\CreditNoteFactory> */
    use HasFactory;
    use LocksFiscalFieldsAfterEmission;

    protected $fillable = [
        // Relaciones
        'invoice_id',
        'cai_range_id',
        'establishment_id',

        // Documento
        'credit_note_number',
        'cai',
        'emission_point',
        'credit_note_date',
        'cai_expiration_date',

        // Razón
        'reason',
        'reason_notes',

        // Snapshot emisor
        'company_name',
        'company_rtn',
        'company_address',
        'company_phone',
        'company_email',

        // Snapshot receptor
        'customer_name',
        'customer_rtn',

        // Snapshot factura origen
        'original_invoice_number',
        'original_invoice_cai',
        'original_invoice_date',

        // Totales (positivos)
        'subtotal',
        'exempt_total',
        'taxable_total',
        'isv',
        'total',

        // Estado
        'is_void',
        'without_cai',
        'pdf_path',

        // Integridad
        'integrity_hash',
        'emitted_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'credit_note_date'       => 'date',
            'cai_expiration_date'    => 'date',
            'original_invoice_date'  => 'date',
            'emitted_at'             => 'datetime',
            'reason'                 => CreditNoteReason::class,
            'subtotal'               => 'decimal:2',
            'exempt_total'           => 'decimal:2',
            'taxable_total'          => 'decimal:2',
            'isv'                    => 'decimal:2',
            'total'                  => 'decimal:2',
            'is_void'                => 'boolean',
            'without_cai'            => 'boolean',
        ];
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function caiRange(): BelongsTo
    {
        return $this->belongsTo(CaiRange::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Scopes ──────────────────────────────────────────────

    public function scopeValid(Builder $query): Builder
    {
        return $query->where('is_void', false);
    }

    public function scopeVoided(Builder $query): Builder
    {
        return $query->where('is_void', true);
    }

    // ─── Accessors ───────────────────────────────────────────

    /**
     * Número mostrado al usuario: agrega "(Sin CAI)" si aplica.
     */
    public function getDisplayNumberAttribute(): string
    {
        return $this->without_cai
            ? "{$this->credit_note_number} (Sin CAI)"
            : $this->credit_note_number;
    }

    // ─── Mutators de estado ──────────────────────────────────
    //
    // La anulación NO es un setter en el modelo: afecta kardex (reversión de
    // EntradaNotaCredito → registrar SalidaAnulacionNotaCredito) y requiere
    // transacción con lockForUpdate + validación de stock suficiente.
    // Toda anulación va por App\Services\CreditNotes\CreditNoteService::voidNotaCredito().
}

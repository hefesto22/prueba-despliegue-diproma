<?php

namespace App\Models;

use App\Concerns\LocksFiscalFieldsAfterEmission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory;
    use LocksFiscalFieldsAfterEmission;

    protected $fillable = [
        'sale_id',
        'cai_range_id',
        'establishment_id',
        'invoice_number',
        'cai',
        'emission_point',
        'invoice_date',
        'cai_expiration_date',
        'company_name',
        'company_rtn',
        'company_address',
        'company_phone',
        'company_email',
        'customer_name',
        'customer_rtn',
        'subtotal',
        'exempt_total',
        'taxable_total',
        'isv',
        'discount',
        'total',
        'is_void',
        'without_cai',
        'pdf_path',
        'integrity_hash',
        'emitted_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'cai_expiration_date' => 'date',
            'emitted_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'exempt_total' => 'decimal:2',
            'taxable_total' => 'decimal:2',
            'isv' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'is_void' => 'boolean',
            'without_cai' => 'boolean',
        ];
    }

    // ─── Relaciones ──────────────────────────────────────

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function caiRange(): BelongsTo
    {
        return $this->belongsTo(CaiRange::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Scopes ──────────────────────────────────────────

    public function scopeValid($query)
    {
        return $query->where('is_void', false);
    }

    public function scopeVoided($query)
    {
        return $query->where('is_void', true);
    }

    // ─── Helpers ─────────────────────────────────────────

    /**
     * Número para mostrar: con prefijo si tiene CAI, o "S/N" si es sin CAI.
     */
    public function getDisplayNumberAttribute(): string
    {
        if ($this->without_cai) {
            return $this->invoice_number . ' (Sin CAI)';
        }

        return $this->invoice_number;
    }

    /**
     * Anular esta factura (marcar como void).
     *
     * ⚠️ @internal — No llamar este método directamente desde UI, controladores,
     * Filament actions ni servicios ajenos al cascade de venta.
     *
     * El flujo correcto de anulación es:
     *   1. Validar período fiscal con FiscalPeriodService::assertCanVoidInvoice()
     *   2. Llamar SaleService::cancel($sale) — el service hace:
     *      - Reversa de kardex con MovementType::EntradaAnulacionVenta
     *      - Devolución de stock con lockForUpdate (atómico)
     *      - Marcado de la venta como SaleStatus::Anulada
     *      - Llamada a ESTE método void() como paso final de la cascada
     *
     * Llamar void() directamente sin pasar por SaleService::cancel() deja el
     * sistema inconsistente: factura anulada pero venta activa y stock sin
     * restaurar. Es un bug silencioso que solo se detecta al reconciliar
     * inventario contra ventas. Si encuentra llamadas directas en el código,
     * considérelo deuda técnica y migre al flujo de cascade.
     *
     * @see \App\Services\Sales\SaleService::cancel()
     * @see \App\Services\FiscalPeriods\FiscalPeriodService::assertCanVoidInvoice()
     */
    public function void(): void
    {
        $this->update(['is_void' => true]);
    }
}

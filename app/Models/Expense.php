<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Gasto contable — egreso del negocio con respaldo fiscal opcional.
 *
 * Esta es la entidad de dominio para gastos. Un Expense representa la
 * realidad fiscal/contable del egreso: cuándo ocurrió, a qué categoría
 * pertenece, cómo se pagó, qué proveedor lo facturó y si genera crédito
 * fiscal de ISV.
 *
 * Relación con CashMovement (kardex de caja):
 *   - Cuando payment_method = Efectivo, ExpenseService crea un CashMovement
 *     vinculado vía cash_movements.expense_id. Ese movimiento afecta el
 *     saldo físico de la caja (lo descuenta del cajón).
 *   - Cuando payment_method ≠ Efectivo (tarjeta, transferencia, cheque),
 *     no existe CashMovement asociado: el gasto queda registrado
 *     contablemente pero no toca el saldo de caja.
 *
 * Datos fiscales son OPCIONALES:
 *   - Hay gastos con factura del proveedor (gasolina, papelería, servicios)
 *     que llevan provider_name + RTN + invoice_number + isv_amount.
 *   - Hay gastos sin factura (taxi, propinas, gastos menores) que solo
 *     llevan amount_total + descripción + categoría.
 *   - El contador decide qué declara como crédito fiscal (is_isv_deductible).
 *
 * @property int $id
 * @property int $establishment_id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $expense_date
 * @property ExpenseCategory $category
 * @property PaymentMethod $payment_method
 * @property string $amount_total
 * @property string|null $taxable_amount
 * @property string|null $isv_amount
 * @property bool $is_isv_deductible
 * @property string $description
 * @property string|null $provider_name
 * @property string|null $provider_rtn
 * @property string|null $provider_invoice_number
 * @property string|null $provider_invoice_cai
 * @property \Illuminate\Support\Carbon|null $provider_invoice_date
 * @property string|null $attachment_path
 */
class Expense extends Model
{
    use HasAuditFields, HasFactory, LogsActivity;

    protected $fillable = [
        'establishment_id',
        'user_id',
        'sale_id',
        'expense_date',
        'category',
        'payment_method',
        'amount_total',
        'taxable_amount',
        'isv_amount',
        'is_isv_deductible',
        'description',
        'provider_name',
        'provider_rtn',
        'provider_invoice_number',
        'provider_invoice_cai',
        'provider_invoice_date',
        'attachment_path',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'category' => ExpenseCategory::class,
            'payment_method' => PaymentMethod::class,
            'amount_total' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'isv_amount' => 'decimal:2',
            'is_isv_deductible' => 'boolean',
            'provider_invoice_date' => 'date',
        ];
    }

    // ─── Activity Log ────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'establishment_id',
                'expense_date',
                'category',
                'payment_method',
                'amount_total',
                'taxable_amount',
                'isv_amount',
                'is_isv_deductible',
                'provider_rtn',
                'provider_invoice_number',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Gasto {$eventName}");
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Venta de origen (solo cuando el gasto fue auto-generado por una venta
     * pagada con tarjeta — comisión bancaria del procesador).
     *
     * Null para gastos manuales (combustible, papelería, etc.).
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * CashMovement vinculado (solo cuando se pagó con Efectivo desde caja chica).
     *
     * HasOne y no MorphOne porque el vínculo es directo vía expense_id.
     * Null si payment_method ≠ Efectivo.
     */
    public function cashMovement(): HasOne
    {
        return $this->hasOne(CashMovement::class, 'expense_id');
    }

    /**
     * Documentos de Compras generados desde este gasto (Fase 1b). Como máximo
     * uno vigente; los anulados quedan como rastro de cuando se desmarcó la
     * factura. Ver ExpenseFiscalDocumentSync.
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * La compra vigente que lleva este gasto al Libro de Compras, si existe.
     */
    public function fiscalDocument(): HasOne
    {
        return $this->hasOne(Purchase::class)
            ->where('status', '!=', PurchaseStatus::Anulada->value);
    }

    // ─── Scopes ──────────────────────────────────────────────

    /**
     * Filtra gastos de un mes calendario (year + month).
     *
     * Usa expense_date (fecha del gasto), no created_at, para alinear con
     * el período fiscal correcto.
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeForMonth(Builder $query, int $year, int $month): Builder
    {
        return $query
            ->whereYear('expense_date', $year)
            ->whereMonth('expense_date', $month);
    }

    /**
     * Filtra por categoría (enum o string).
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeOfCategory(Builder $query, ExpenseCategory|string $category): Builder
    {
        $value = $category instanceof ExpenseCategory ? $category->value : $category;

        return $query->where('category', $value);
    }

    /**
     * Filtra por método de pago (enum o string).
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeOfPaymentMethod(Builder $query, PaymentMethod|string $method): Builder
    {
        $value = $method instanceof PaymentMethod ? $method->value : $method;

        return $query->where('payment_method', $value);
    }

    /**
     * Solo gastos marcados como deducibles de ISV (generan crédito fiscal).
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeDeducibles(Builder $query): Builder
    {
        return $query->where('is_isv_deductible', true);
    }

    /**
     * Filtra por sucursal.
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeForEstablishment(Builder $query, int $establishmentId): Builder
    {
        return $query->where('establishment_id', $establishmentId);
    }

    // ─── Helpers de dominio ──────────────────────────────────

    /**
     * ¿Este gasto afecta el saldo físico de caja?
     *
     * Solo los gastos en efectivo entran al kardex. Los demás métodos no
     * tocan el cajón. Delegamos a PaymentMethod::affectsCashBalance() para
     * mantener una sola fuente de verdad.
     */
    public function affectsCashBalance(): bool
    {
        return $this->payment_method->affectsCashBalance();
    }

    /**
     * ¿Es un gasto marcado deducible ANTES de la Fase 1b?
     *
     * Esos gastos no tienen importe gravado (la columna no existía) y, por
     * decisión del 2026-09-28, no se copian al Libro de Compras. Dejan de ser
     * "heredados" en cuanto alguien les carga el importe gravado: esa es la
     * forma explícita de mandar un gasto viejo al libro.
     */
    public function isLegacyDeductible(): bool
    {
        return (bool) $this->is_isv_deductible && $this->taxable_amount === null;
    }

    /**
     * Monto base (antes de ISV) — útil para reportes.
     *
     * Si isv_amount es null devuelve amount_total (no hay ISV desglosado).
     */
    public function getAmountBaseAttribute(): float
    {
        return (float) $this->amount_total - (float) ($this->isv_amount ?? 0);
    }
}

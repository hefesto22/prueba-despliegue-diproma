<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    use HasFactory, HasAuditFields, LogsActivity;

    protected $fillable = [
        'establishment_id',
        'user_id',
        'expense_date',
        'category',
        'payment_method',
        'amount_total',
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
            'expense_date'           => 'date',
            'category'               => ExpenseCategory::class,
            'payment_method'         => PaymentMethod::class,
            'amount_total'           => 'decimal:2',
            'isv_amount'             => 'decimal:2',
            'is_isv_deductible'      => 'boolean',
            'provider_invoice_date'  => 'date',
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
     * CashMovement vinculado (solo cuando se pagó con Efectivo desde caja chica).
     *
     * HasOne y no MorphOne porque el vínculo es directo vía expense_id.
     * Null si payment_method ≠ Efectivo.
     */
    public function cashMovement(): HasOne
    {
        return $this->hasOne(CashMovement::class, 'expense_id');
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
     * Monto base (antes de ISV) — útil para reportes.
     *
     * Si isv_amount es null devuelve amount_total (no hay ISV desglosado).
     */
    public function getAmountBaseAttribute(): float
    {
        return (float) $this->amount_total - (float) ($this->isv_amount ?? 0);
    }
}

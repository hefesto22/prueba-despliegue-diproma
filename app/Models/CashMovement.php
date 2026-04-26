<?php

namespace App\Models;

use App\Enums\CashMovementType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Movimiento de caja — línea individual asociada a una CashSession.
 *
 * `amount` siempre es positivo. El signo contable lo determina `type`
 * (CashMovementType::isInflow() / isOutflow()).
 *
 * Solo movimientos con `payment_method = efectivo` afectan el saldo físico
 * de caja. Los demás se registran para reportes y cuadre por método.
 *
 * @property int $id
 * @property int $cash_session_id
 * @property int $user_id
 * @property CashMovementType $type
 * @property PaymentMethod $payment_method
 * @property string $amount
 * @property ExpenseCategory|null $category
 * @property string|null $description
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $expense_id
 * @property \Illuminate\Support\Carbon $occurred_at
 */
class CashMovement extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'cash_session_id',
        'user_id',
        'type',
        'payment_method',
        'amount',
        'category',
        'description',
        'reference_type',
        'reference_id',
        'expense_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'payment_method' => PaymentMethod::class,
            'category' => ExpenseCategory::class,
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    // ─── Activity Log ────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['cash_session_id', 'type', 'payment_method', 'amount', 'category'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Movimiento de caja {$eventName}");
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Asociación polimórfica opcional (ej: Sale para sale_income).
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Expense vinculado (solo cuando type=expense + payment_method=efectivo
     * y el movimiento nació desde ExpenseService::register).
     *
     * Null si el movimiento es de otro tipo (sale_income, opening_balance, etc.)
     * o si el caller registró el expense directo en cash_movements sin pasar
     * por ExpenseService — ese caller legacy no debería existir tras la
     * implementación actual, pero el campo es nullable para preservar
     * movimientos que no nacen de Expense.
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    // ─── Helpers de dominio ──────────────────────────────────

    /**
     * ¿Este movimiento afecta el saldo físico de caja?
     *
     * Solo los movimientos en efectivo entran/salen del cajón.
     */
    public function affectsCashBalance(): bool
    {
        return $this->payment_method->affectsCashBalance();
    }

    // ─── Scopes ──────────────────────────────────────────────

    /**
     * Solo movimientos en efectivo (los que afectan el saldo de caja).
     *
     * @param  Builder<CashMovement>  $query
     * @return Builder<CashMovement>
     */
    public function scopeInCash(Builder $query): Builder
    {
        return $query->where('payment_method', PaymentMethod::Efectivo->value);
    }

    /**
     * Filtrar por tipo de movimiento.
     *
     * @param  Builder<CashMovement>  $query
     * @return Builder<CashMovement>
     */
    public function scopeOfType(Builder $query, CashMovementType $type): Builder
    {
        return $query->where('type', $type->value);
    }
}

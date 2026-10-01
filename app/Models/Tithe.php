<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasAuditFields;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Diezmo calculado para un mes, con la foto de las cifras usadas.
 * Lo escribe TitheService; un registro por mes.
 *
 * @property int $year
 * @property int $month
 * @property string $net_profit
 * @property string $extra_deductions
 * @property string $base_amount
 * @property string $amount
 */
class Tithe extends Model
{
    use HasAuditFields, HasFactory;

    protected $fillable = [
        'year',
        'month',
        'revenue',
        'cost',
        'expense_purchases',
        'card_fees',
        'net_profit',
        'extra_deductions',
        'base_amount',
        'rate',
        'amount',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'revenue' => 'decimal:2',
            'cost' => 'decimal:2',
            'expense_purchases' => 'decimal:2',
            'card_fees' => 'decimal:2',
            'net_profit' => 'decimal:2',
            'extra_deductions' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(TitheDeduction::class);
    }

    /**
     * "Septiembre 2026".
     */
    public function periodLabel(): string
    {
        return ucfirst(CarbonImmutable::create($this->year, $this->month, 1)->translatedFormat('F Y'));
    }
}

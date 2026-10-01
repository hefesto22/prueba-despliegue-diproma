<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pago que el sistema no conoce y se restó para calcular un diezmo
 * (pago de empleados, etc.).
 */
class TitheDeduction extends Model
{
    use HasFactory;

    protected $fillable = [
        'tithe_id',
        'concept',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function tithe(): BelongsTo
    {
        return $this->belongsTo(Tithe::class);
    }
}

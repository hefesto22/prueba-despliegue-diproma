<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpecOption extends Model
{
    protected $fillable = [
        'field_key',
        'value',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    // ─── Scopes ─────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForField($query, string $fieldKey)
    {
        return $query->where('field_key', $fieldKey);
    }

    // ─── Helpers ────────────────────────────────────────────

    /**
     * Buscar opciones para un campo, con filtro opcional.
     * Retorna array [value => value] para Filament Select.
     */
    public static function searchOptions(string $fieldKey, ?string $search = null, int $limit = 50): array
    {
        $query = static::active()
            ->forField($fieldKey)
            ->orderBy('sort_order')
            ->orderBy('value');

        if (filled($search)) {
            $search = mb_strtoupper(trim($search));
            $query->where('value', 'like', "%{$search}%");
        }

        return $query
            ->limit($limit)
            ->pluck('value', 'value')
            ->toArray();
    }

    /**
     * Asegurar que un valor existe como opción.
     * Si no existe, lo crea (para valores personalizados).
     */
    public static function ensureExists(string $fieldKey, string $value): void
    {
        if (blank($value)) {
            return;
        }

        $value = mb_strtoupper(trim($value));

        static::firstOrCreate(
            ['field_key' => $fieldKey, 'value' => $value],
            ['sort_order' => 999, 'is_active' => true]
        );
    }
}

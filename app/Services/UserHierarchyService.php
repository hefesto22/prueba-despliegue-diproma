<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserHierarchyService
{
    /**
     * Tiempo de cache en minutos para la jerarquia de descendientes.
     */
    private const CACHE_TTL_MINUTES = 5;

    /**
     * Prefijo de cache para descendientes.
     */
    private const CACHE_PREFIX = 'user_descendants_';

    /**
     * Obtener todos los IDs de la rama descendente usando CTE recursivo.
     * Usa una sola query SQL en lugar de N queries recursivas.
     * Resultado cacheado para evitar recalculos frecuentes.
     *
     * @return array<int>
     */
    public function getDescendantIds(User $user): array
    {
        return Cache::remember(
            $this->cacheKey($user->id),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->queryDescendants($user->id)
        );
    }

    /**
     * Obtener todos los IDs visibles para un usuario.
     * Incluye: su propio ID + toda su rama descendente.
     *
     * @return array<int>
     */
    public function getVisibleUserIds(User $user): array
    {
        return array_merge([$user->id], $this->getDescendantIds($user));
    }

    /**
     * Verificar si un usuario es visible para otro.
     */
    public function isVisibleTo(User $target, User $viewer): bool
    {
        if ($viewer->isSuperAdmin()) {
            return true;
        }

        return in_array($target->id, $this->getVisibleUserIds($viewer));
    }

    /**
     * Invalidar la cache de descendientes de un usuario y su padre.
     */
    public function clearCache(User $user): void
    {
        Cache::forget($this->cacheKey($user->id));

        if ($user->created_by) {
            Cache::forget($this->cacheKey($user->created_by));
        }
    }

    /**
     * Ejecutar la query CTE recursiva para obtener descendientes.
     *
     * @return array<int>
     */
    private function queryDescendants(int $userId): array
    {
        $results = DB::select("
            WITH RECURSIVE descendants AS (
                SELECT id FROM users WHERE created_by = ? AND deleted_at IS NULL
                UNION ALL
                SELECT u.id FROM users u
                INNER JOIN descendants d ON u.created_by = d.id
                WHERE u.deleted_at IS NULL
            )
            SELECT id FROM descendants
        ", [$userId]);

        return array_map(fn ($row) => (int) $row->id, $results);
    }

    /**
     * Generar clave de cache consistente.
     */
    private function cacheKey(int $userId): string
    {
        return self::CACHE_PREFIX . $userId;
    }
}

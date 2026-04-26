<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CashMovement;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Policy estándar Filament Shield para CashMovement.
 *
 * Los movimientos se crean exclusivamente vía:
 *   - CashSessionService (apertura/cierre automáticos)
 *   - SaleService (sale_income al completar venta, sale_cancellation al anular)
 *   - Action "Registrar gasto" del CashSessionResource (C3.4)
 *
 * Nunca se editan ni eliminan manualmente — son historia operativa. Los
 * permisos `Update` y `Delete` existen por contrato Shield pero NO deben
 * concederse a ningún rol en producción. El RelationManager de C3.5 es
 * read-only por diseño.
 */
class CashMovementPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CashMovement');
    }

    public function view(AuthUser $authUser, CashMovement $cashMovement): bool
    {
        return $authUser->can('View:CashMovement');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CashMovement');
    }

    public function update(AuthUser $authUser, CashMovement $cashMovement): bool
    {
        return $authUser->can('Update:CashMovement');
    }

    public function delete(AuthUser $authUser, CashMovement $cashMovement): bool
    {
        return $authUser->can('Delete:CashMovement');
    }

    public function restore(AuthUser $authUser, CashMovement $cashMovement): bool
    {
        return $authUser->can('Restore:CashMovement');
    }

    public function forceDelete(AuthUser $authUser, CashMovement $cashMovement): bool
    {
        return $authUser->can('ForceDelete:CashMovement');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CashMovement');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CashMovement');
    }

    public function replicate(AuthUser $authUser, CashMovement $cashMovement): bool
    {
        return $authUser->can('Replicate:CashMovement');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CashMovement');
    }
}

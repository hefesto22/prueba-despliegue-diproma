<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\InventoryMovement;
use Illuminate\Auth\Access\HandlesAuthorization;

class InventoryMovementPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:InventoryMovement');
    }

    public function view(AuthUser $authUser, InventoryMovement $inventoryMovement): bool
    {
        return $authUser->can('View:InventoryMovement');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:InventoryMovement');
    }

    public function update(AuthUser $authUser, InventoryMovement $inventoryMovement): bool
    {
        return $authUser->can('Update:InventoryMovement');
    }

    public function delete(AuthUser $authUser, InventoryMovement $inventoryMovement): bool
    {
        return $authUser->can('Delete:InventoryMovement');
    }

    public function restore(AuthUser $authUser, InventoryMovement $inventoryMovement): bool
    {
        return $authUser->can('Restore:InventoryMovement');
    }

    public function forceDelete(AuthUser $authUser, InventoryMovement $inventoryMovement): bool
    {
        return $authUser->can('ForceDelete:InventoryMovement');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:InventoryMovement');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:InventoryMovement');
    }

    public function replicate(AuthUser $authUser, InventoryMovement $inventoryMovement): bool
    {
        return $authUser->can('Replicate:InventoryMovement');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:InventoryMovement');
    }

}
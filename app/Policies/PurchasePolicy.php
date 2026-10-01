<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Purchase;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PurchasePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Purchase');
    }

    public function view(AuthUser $authUser, Purchase $purchase): bool
    {
        return $authUser->can('View:Purchase');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Purchase');
    }

    public function update(AuthUser $authUser, Purchase $purchase): bool
    {
        return $authUser->can('Update:Purchase');
    }

    public function delete(AuthUser $authUser, Purchase $purchase): bool
    {
        return $authUser->can('Delete:Purchase');
    }

    public function restore(AuthUser $authUser, Purchase $purchase): bool
    {
        return $authUser->can('Restore:Purchase');
    }

    public function forceDelete(AuthUser $authUser, Purchase $purchase): bool
    {
        return $authUser->can('ForceDelete:Purchase');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Purchase');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Purchase');
    }

    public function replicate(AuthUser $authUser, Purchase $purchase): bool
    {
        return $authUser->can('Replicate:Purchase');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Purchase');
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\CaiRange;
use Illuminate\Auth\Access\HandlesAuthorization;

class CaiRangePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CaiRange');
    }

    public function view(AuthUser $authUser, CaiRange $caiRange): bool
    {
        return $authUser->can('View:CaiRange');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CaiRange');
    }

    public function update(AuthUser $authUser, CaiRange $caiRange): bool
    {
        return $authUser->can('Update:CaiRange');
    }

    public function delete(AuthUser $authUser, CaiRange $caiRange): bool
    {
        return $authUser->can('Delete:CaiRange');
    }

    public function restore(AuthUser $authUser, CaiRange $caiRange): bool
    {
        return $authUser->can('Restore:CaiRange');
    }

    public function forceDelete(AuthUser $authUser, CaiRange $caiRange): bool
    {
        return $authUser->can('ForceDelete:CaiRange');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CaiRange');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CaiRange');
    }

    public function replicate(AuthUser $authUser, CaiRange $caiRange): bool
    {
        return $authUser->can('Replicate:CaiRange');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CaiRange');
    }

}
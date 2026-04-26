<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\SpecOption;
use Illuminate\Auth\Access\HandlesAuthorization;

class SpecOptionPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SpecOption');
    }

    public function view(AuthUser $authUser, SpecOption $specOption): bool
    {
        return $authUser->can('View:SpecOption');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SpecOption');
    }

    public function update(AuthUser $authUser, SpecOption $specOption): bool
    {
        return $authUser->can('Update:SpecOption');
    }

    public function delete(AuthUser $authUser, SpecOption $specOption): bool
    {
        return $authUser->can('Delete:SpecOption');
    }

    public function restore(AuthUser $authUser, SpecOption $specOption): bool
    {
        return $authUser->can('Restore:SpecOption');
    }

    public function forceDelete(AuthUser $authUser, SpecOption $specOption): bool
    {
        return $authUser->can('ForceDelete:SpecOption');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SpecOption');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SpecOption');
    }

    public function replicate(AuthUser $authUser, SpecOption $specOption): bool
    {
        return $authUser->can('Replicate:SpecOption');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SpecOption');
    }

}
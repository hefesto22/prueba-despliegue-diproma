<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\IsvRetentionReceived;
use Illuminate\Auth\Access\HandlesAuthorization;

class IsvRetentionReceivedPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:IsvRetentionReceived');
    }

    public function view(AuthUser $authUser, IsvRetentionReceived $isvRetentionReceived): bool
    {
        return $authUser->can('View:IsvRetentionReceived');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:IsvRetentionReceived');
    }

    public function update(AuthUser $authUser, IsvRetentionReceived $isvRetentionReceived): bool
    {
        return $authUser->can('Update:IsvRetentionReceived');
    }

    public function delete(AuthUser $authUser, IsvRetentionReceived $isvRetentionReceived): bool
    {
        return $authUser->can('Delete:IsvRetentionReceived');
    }

    public function restore(AuthUser $authUser, IsvRetentionReceived $isvRetentionReceived): bool
    {
        return $authUser->can('Restore:IsvRetentionReceived');
    }

    public function forceDelete(AuthUser $authUser, IsvRetentionReceived $isvRetentionReceived): bool
    {
        return $authUser->can('ForceDelete:IsvRetentionReceived');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:IsvRetentionReceived');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:IsvRetentionReceived');
    }

    public function replicate(AuthUser $authUser, IsvRetentionReceived $isvRetentionReceived): bool
    {
        return $authUser->can('Replicate:IsvRetentionReceived');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:IsvRetentionReceived');
    }

}
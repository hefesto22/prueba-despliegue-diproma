<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\CashSession;
use Illuminate\Auth\Access\HandlesAuthorization;

class CashSessionPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CashSession');
    }

    public function view(AuthUser $authUser, CashSession $cashSession): bool
    {
        return $authUser->can('View:CashSession');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CashSession');
    }

    public function update(AuthUser $authUser, CashSession $cashSession): bool
    {
        return $authUser->can('Update:CashSession');
    }

    public function delete(AuthUser $authUser, CashSession $cashSession): bool
    {
        return $authUser->can('Delete:CashSession');
    }

    public function restore(AuthUser $authUser, CashSession $cashSession): bool
    {
        return $authUser->can('Restore:CashSession');
    }

    public function forceDelete(AuthUser $authUser, CashSession $cashSession): bool
    {
        return $authUser->can('ForceDelete:CashSession');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CashSession');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CashSession');
    }

    public function replicate(AuthUser $authUser, CashSession $cashSession): bool
    {
        return $authUser->can('Replicate:CashSession');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CashSession');
    }

}
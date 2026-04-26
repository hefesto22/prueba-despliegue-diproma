<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\CreditNote;
use Illuminate\Auth\Access\HandlesAuthorization;

class CreditNotePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CreditNote');
    }

    public function view(AuthUser $authUser, CreditNote $creditNote): bool
    {
        return $authUser->can('View:CreditNote');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CreditNote');
    }

    public function update(AuthUser $authUser, CreditNote $creditNote): bool
    {
        return $authUser->can('Update:CreditNote');
    }

    public function delete(AuthUser $authUser, CreditNote $creditNote): bool
    {
        return $authUser->can('Delete:CreditNote');
    }

    public function restore(AuthUser $authUser, CreditNote $creditNote): bool
    {
        return $authUser->can('Restore:CreditNote');
    }

    public function forceDelete(AuthUser $authUser, CreditNote $creditNote): bool
    {
        return $authUser->can('ForceDelete:CreditNote');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CreditNote');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CreditNote');
    }

    public function replicate(AuthUser $authUser, CreditNote $creditNote): bool
    {
        return $authUser->can('Replicate:CreditNote');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CreditNote');
    }

}
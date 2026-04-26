<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Sale;
use Illuminate\Auth\Access\HandlesAuthorization;

class SalePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Sale');
    }

    public function view(AuthUser $authUser, Sale $sale): bool
    {
        return $authUser->can('View:Sale');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Sale');
    }

    public function update(AuthUser $authUser, Sale $sale): bool
    {
        return $authUser->can('Update:Sale');
    }

    public function delete(AuthUser $authUser, Sale $sale): bool
    {
        return $authUser->can('Delete:Sale');
    }

    public function restore(AuthUser $authUser, Sale $sale): bool
    {
        return $authUser->can('Restore:Sale');
    }

    public function forceDelete(AuthUser $authUser, Sale $sale): bool
    {
        return $authUser->can('ForceDelete:Sale');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Sale');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Sale');
    }

    public function replicate(AuthUser $authUser, Sale $sale): bool
    {
        return $authUser->can('Replicate:Sale');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Sale');
    }

}
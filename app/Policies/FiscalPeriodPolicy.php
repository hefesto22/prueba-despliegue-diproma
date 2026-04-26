<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\FiscalPeriod;
use Illuminate\Auth\Access\HandlesAuthorization;

class FiscalPeriodPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FiscalPeriod');
    }

    public function view(AuthUser $authUser, FiscalPeriod $fiscalPeriod): bool
    {
        return $authUser->can('View:FiscalPeriod');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:FiscalPeriod');
    }

    public function update(AuthUser $authUser, FiscalPeriod $fiscalPeriod): bool
    {
        return $authUser->can('Update:FiscalPeriod');
    }

    public function delete(AuthUser $authUser, FiscalPeriod $fiscalPeriod): bool
    {
        return $authUser->can('Delete:FiscalPeriod');
    }

    public function restore(AuthUser $authUser, FiscalPeriod $fiscalPeriod): bool
    {
        return $authUser->can('Restore:FiscalPeriod');
    }

    public function forceDelete(AuthUser $authUser, FiscalPeriod $fiscalPeriod): bool
    {
        return $authUser->can('ForceDelete:FiscalPeriod');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:FiscalPeriod');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:FiscalPeriod');
    }

    public function replicate(AuthUser $authUser, FiscalPeriod $fiscalPeriod): bool
    {
        return $authUser->can('Replicate:FiscalPeriod');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:FiscalPeriod');
    }

}
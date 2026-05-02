<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeviceCategory;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DeviceCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DeviceCategory');
    }

    public function view(AuthUser $authUser, DeviceCategory $deviceCategory): bool
    {
        return $authUser->can('View:DeviceCategory');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DeviceCategory');
    }

    public function update(AuthUser $authUser, DeviceCategory $deviceCategory): bool
    {
        return $authUser->can('Update:DeviceCategory');
    }

    public function delete(AuthUser $authUser, DeviceCategory $deviceCategory): bool
    {
        return $authUser->can('Delete:DeviceCategory');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DeviceCategory');
    }
}

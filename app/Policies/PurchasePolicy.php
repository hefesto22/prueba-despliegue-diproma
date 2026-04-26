<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Purchase;
use Illuminate\Auth\Access\HandlesAuthorization;

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

    /**
     * Update + delete requieren AMBAS condiciones:
     *   1. El usuario tiene el permiso del rol (Update:Purchase / Delete:Purchase)
     *   2. La compra está en un estado que permite edición (isEditable() → solo Borrador)
     *
     * Defense in depth: el check de estado YA vive en la UI (EditPurchase::authorizeAccess
     * y `visible(fn ... isEditable())` en tabla y página de vista). Replicarlo aquí
     * garantiza que cualquier ruta futura — API REST, comando artisan, job, código
     * que llame Gate::authorize() directamente — respete la regla sin depender de
     * que cada caller la implemente. Una compra Confirmada o Anulada no se edita
     * jamás: tocaría stock, costo promedio y kardex ya consolidados.
     */
    public function update(AuthUser $authUser, Purchase $purchase): bool
    {
        return $authUser->can('Update:Purchase') && $purchase->isEditable();
    }

    public function delete(AuthUser $authUser, Purchase $purchase): bool
    {
        return $authUser->can('Delete:Purchase') && $purchase->isEditable();
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
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerCredit;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Policy del modelo CustomerCredit.
 *
 * Los créditos a favor del cliente se generan automáticamente desde
 * RepairService cuando un anticipo se convierte en crédito. Update y delete
 * son restrictivos: la regla de negocio es que `amount` jamás cambia
 * (auditoría) y solo `balance` decrementa al usarse en cobros futuros.
 */
class CustomerCreditPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CustomerCredit');
    }

    public function view(AuthUser $authUser, CustomerCredit $customerCredit): bool
    {
        return $authUser->can('View:CustomerCredit');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CustomerCredit');
    }

    public function update(AuthUser $authUser, CustomerCredit $customerCredit): bool
    {
        return $authUser->can('Update:CustomerCredit');
    }

    public function delete(AuthUser $authUser, CustomerCredit $customerCredit): bool
    {
        return $authUser->can('Delete:CustomerCredit');
    }
}

<?php

namespace App\Modules\Employees\Policies;

use App\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Models\Store;

class EmployeePolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('employes.voir');
    }

    public function view(User $user, Employee $employee, Store $store): bool
    {
        return $employee->boutique_id === $store->id && $user->can('employes.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('employes.gerer');
    }

    /** Modifier, supprimer ou payer un employé. */
    public function manage(User $user, Employee $employee, Store $store): bool
    {
        return $employee->boutique_id === $store->id && $user->can('employes.gerer');
    }
}

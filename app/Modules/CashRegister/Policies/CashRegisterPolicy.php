<?php

namespace App\Modules\CashRegister\Policies;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Tenancy\Models\Store;

class CashRegisterPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('caisse.voir');
    }

    public function view(User $user, CashRegister $cashRegister, Store $store): bool
    {
        return $cashRegister->boutique_id === $store->id && $user->can('caisse.voir');
    }

    /** Defining/editing the till itself is a setup task, gated separately from day-to-day session operations. */
    public function create(User $user, Store $store): bool
    {
        return $user->can('caisse.gerer');
    }

    public function update(User $user, CashRegister $cashRegister, Store $store): bool
    {
        return $cashRegister->boutique_id === $store->id && $user->can('caisse.gerer');
    }
}

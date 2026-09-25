<?php

namespace App\Modules\CashRegister\Policies;

use App\Models\User;
use App\Modules\Tenancy\Models\Store;

class CashMovementPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('cash_register.view');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('cash_register.adjust');
    }
}

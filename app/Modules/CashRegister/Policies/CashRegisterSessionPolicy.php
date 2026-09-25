<?php

namespace App\Modules\CashRegister\Policies;

use App\Models\User;
use App\Modules\Tenancy\Models\Store;

/**
 * Neither {cashRegister} nor {session} is passed to these — both
 * already went through scoped route model binding, so there's no
 * client-supplied id left to double-check (same reasoning as
 * StockMovementPolicy in Inventory).
 */
class CashRegisterSessionPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('cash_register.view');
    }

    public function open(User $user, Store $store): bool
    {
        return $user->can('cash_register.open');
    }

    public function close(User $user, Store $store): bool
    {
        return $user->can('cash_register.close');
    }
}

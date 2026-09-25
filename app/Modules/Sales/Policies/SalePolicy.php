<?php

namespace App\Modules\Sales\Policies;

use App\Models\User;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Store;

class SalePolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('sales.view');
    }

    public function view(User $user, Sale $sale, Store $store): bool
    {
        return $sale->store_id === $store->id && $user->can('sales.view');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('sales.create');
    }
}

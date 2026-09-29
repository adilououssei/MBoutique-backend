<?php

namespace App\Modules\Sales\Policies;

use App\Models\User;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Store;

class SalePolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('ventes.voir');
    }

    public function view(User $user, Sale $sale, Store $store): bool
    {
        return $sale->boutique_id === $store->id && $user->can('ventes.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('ventes.creer');
    }
}

<?php

namespace App\Modules\Suppliers\Policies;

use App\Models\User;
use App\Modules\Suppliers\Models\Purchase;
use App\Modules\Tenancy\Models\Store;

class PurchasePolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('achats.voir');
    }

    public function view(User $user, Purchase $purchase, Store $store): bool
    {
        return $purchase->boutique_id === $store->id && $user->can('achats.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('achats.creer');
    }

    /** Enregistrer un règlement : même droit que créer un achat. */
    public function pay(User $user, Purchase $purchase, Store $store): bool
    {
        return $purchase->boutique_id === $store->id && $user->can('achats.creer');
    }
}

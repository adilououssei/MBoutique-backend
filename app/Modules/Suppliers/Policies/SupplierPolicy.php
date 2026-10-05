<?php

namespace App\Modules\Suppliers\Policies;

use App\Models\User;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Store;

class SupplierPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('fournisseurs.voir');
    }

    public function view(User $user, Supplier $supplier, Store $store): bool
    {
        return $supplier->boutique_id === $store->id && $user->can('fournisseurs.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('fournisseurs.creer');
    }

    public function update(User $user, Supplier $supplier, Store $store): bool
    {
        return $supplier->boutique_id === $store->id && $user->can('fournisseurs.modifier');
    }

    public function delete(User $user, Supplier $supplier, Store $store): bool
    {
        return $supplier->boutique_id === $store->id && $user->can('fournisseurs.supprimer');
    }
}

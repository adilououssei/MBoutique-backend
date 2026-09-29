<?php

namespace App\Modules\Customers\Policies;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Store;

/**
 * Mirrors CategoryPolicy exactly: "belongs to the right store" (defense
 * in depth on top of scoped route binding) combined with the spatie
 * permission check, in one place.
 */
class CustomerPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('clients.voir');
    }

    public function view(User $user, Customer $customer, Store $store): bool
    {
        return $customer->boutique_id === $store->id && $user->can('clients.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('clients.creer');
    }

    public function update(User $user, Customer $customer, Store $store): bool
    {
        return $customer->boutique_id === $store->id && $user->can('clients.modifier');
    }

    public function delete(User $user, Customer $customer, Store $store): bool
    {
        return $customer->boutique_id === $store->id && $user->can('clients.supprimer');
    }
}

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
        return $user->can('customers.view');
    }

    public function view(User $user, Customer $customer, Store $store): bool
    {
        return $customer->store_id === $store->id && $user->can('customers.view');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('customers.create');
    }

    public function update(User $user, Customer $customer, Store $store): bool
    {
        return $customer->store_id === $store->id && $user->can('customers.update');
    }

    public function delete(User $user, Customer $customer, Store $store): bool
    {
        return $customer->store_id === $store->id && $user->can('customers.delete');
    }
}

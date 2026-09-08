<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\Models\Store;

/**
 * Combines "does this belong to the right store" (defense in depth on
 * top of scoped route binding — docs/multi-tenancy.md Couche 3) with
 * "does this user have the permission" (spatie, Couche 4) in one place,
 * mirroring the SalePolicy example in docs/multi-tenancy.md.
 */
class CategoryPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('categories.view');
    }

    public function view(User $user, Category $category, Store $store): bool
    {
        return $category->store_id === $store->id && $user->can('categories.view');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('categories.create');
    }

    public function update(User $user, Category $category, Store $store): bool
    {
        return $category->store_id === $store->id && $user->can('categories.update');
    }

    public function delete(User $user, Category $category, Store $store): bool
    {
        return $category->store_id === $store->id && $user->can('categories.delete');
    }
}

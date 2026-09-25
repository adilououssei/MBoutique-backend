<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Store;

class ProductPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('products.view');
    }

    public function view(User $user, Product $product, Store $store): bool
    {
        return $product->store_id === $store->id && $user->can('products.view');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('products.create');
    }

    public function import(User $user, Store $store): bool
    {
        return $user->can('products.import');
    }

    public function update(User $user, Product $product, Store $store): bool
    {
        return $product->store_id === $store->id && $user->can('products.update');
    }

    public function delete(User $user, Product $product, Store $store): bool
    {
        return $product->store_id === $store->id && $user->can('products.delete');
    }
}

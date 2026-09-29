<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Store;

class ProductPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('produits.voir');
    }

    public function view(User $user, Product $product, Store $store): bool
    {
        return $product->boutique_id === $store->id && $user->can('produits.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('produits.creer');
    }

    public function import(User $user, Store $store): bool
    {
        return $user->can('produits.importer');
    }

    public function update(User $user, Product $product, Store $store): bool
    {
        return $product->boutique_id === $store->id && $user->can('produits.modifier');
    }

    public function delete(User $user, Product $product, Store $store): bool
    {
        return $product->boutique_id === $store->id && $user->can('produits.supprimer');
    }
}

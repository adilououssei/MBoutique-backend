<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Tenancy\Models\Store;

/**
 * Stock is never a route parameter (routes are keyed by {product}, see
 * docs/inventory.md), so unlike CategoryPolicy/ProductPolicy there is no
 * client-supplied Stock id to double-check against $store — the
 * product itself already went through scoped route model binding.
 */
class StockPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('stock.voir');
    }

    /** Updating the minimum_quantity threshold — not a stock movement, gated the same as a manual adjustment. */
    public function update(User $user, Store $store): bool
    {
        return $user->can('stock.ajuster');
    }
}

<?php

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Tenancy\Models\Store;

/**
 * `create` takes the requested StockMovementType because it's not one
 * permission for the whole endpoint: recording a stocktake is
 * deliberately gated separately from an ordinary entry/exit adjustment
 * (docs/inventory.md §"Permissions", following the Phase 4.1 brief's own
 * distinction between "ajuster" and "faire un inventaire").
 */
class StockMovementPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('stock.voir');
    }

    public function create(User $user, Store $store, StockMovementType $type): bool
    {
        return $type === StockMovementType::Stocktake
            ? $user->can('stock.inventorier')
            : $user->can('stock.ajuster');
    }
}

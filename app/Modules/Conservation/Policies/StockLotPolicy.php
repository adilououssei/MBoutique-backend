<?php

namespace App\Modules\Conservation\Policies;

use App\Models\User;
use App\Modules\Conservation\Models\StockLot;
use App\Modules\Tenancy\Models\Store;

/** Mêmes droits que le stock : voir (`stock.voir`), corriger et déclarer une perte (`stock.ajuster`). */
class StockLotPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('stock.voir');
    }

    public function update(User $user, StockLot $lot, Store $store): bool
    {
        return $lot->boutique_id === $store->id && $user->can('stock.ajuster');
    }

    /** Les pertes valorisées révèlent les prix d'achat : réservées à qui ajuste le stock. */
    public function viewLosses(User $user, Store $store): bool
    {
        return $user->can('stock.ajuster');
    }
}

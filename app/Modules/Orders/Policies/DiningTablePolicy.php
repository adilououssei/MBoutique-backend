<?php

namespace App\Modules\Orders\Policies;

use App\Models\User;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Tenancy\Models\Store;

class DiningTablePolicy
{
    /** Le plan de salle sert à quiconque prend des commandes. */
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('commandes.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('tables.gerer');
    }

    public function update(User $user, DiningTable $table, Store $store): bool
    {
        return $table->boutique_id === $store->id && $user->can('tables.gerer');
    }
}

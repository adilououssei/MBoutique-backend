<?php

namespace App\Modules\Orders\Policies;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Store;

class OrderPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('commandes.voir');
    }

    public function view(User $user, Order $order, Store $store): bool
    {
        return $order->boutique_id === $store->id && $user->can('commandes.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('commandes.creer');
    }

    /** Lignes, en-tête, étape de préparation. */
    public function update(User $user, Order $order, Store $store): bool
    {
        return $order->boutique_id === $store->id && $user->can('commandes.modifier');
    }

    /** Encaisser crée une vente : il faut aussi le droit de vendre. */
    public function checkout(User $user, Order $order, Store $store): bool
    {
        return $this->update($user, $order, $store) && $user->can('ventes.creer');
    }

    public function cancel(User $user, Order $order, Store $store): bool
    {
        return $order->boutique_id === $store->id && $user->can('commandes.annuler');
    }
}

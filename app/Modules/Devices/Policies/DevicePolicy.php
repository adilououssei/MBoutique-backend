<?php

namespace App\Modules\Devices\Policies;

use App\Models\User;
use App\Modules\Devices\Models\Device;
use App\Modules\Tenancy\Models\Store;

/** Mêmes droits que le stock : consulter (`stock.voir`), enregistrer et changer l'état (`stock.ajuster`). */
class DevicePolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('stock.voir');
    }

    public function view(User $user, Device $device, Store $store): bool
    {
        return $device->boutique_id === $store->id && $user->can('stock.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('stock.ajuster');
    }

    public function update(User $user, Device $device, Store $store): bool
    {
        return $device->boutique_id === $store->id && $user->can('stock.ajuster');
    }
}

<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\Service;
use App\Modules\Tenancy\Models\Store;

class ServicePolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('services.voir');
    }

    public function view(User $user, Service $service, Store $store): bool
    {
        return $service->boutique_id === $store->id && $user->can('services.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('services.creer');
    }

    public function update(User $user, Service $service, Store $store): bool
    {
        return $service->boutique_id === $store->id && $user->can('services.modifier');
    }

    public function delete(User $user, Service $service, Store $store): bool
    {
        return $service->boutique_id === $store->id && $user->can('services.supprimer');
    }
}

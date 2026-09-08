<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\Service;
use App\Modules\Tenancy\Models\Store;

class ServicePolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('services.view');
    }

    public function view(User $user, Service $service, Store $store): bool
    {
        return $service->store_id === $store->id && $user->can('services.view');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('services.create');
    }

    public function update(User $user, Service $service, Store $store): bool
    {
        return $service->store_id === $store->id && $user->can('services.update');
    }

    public function delete(User $user, Service $service, Store $store): bool
    {
        return $service->store_id === $store->id && $user->can('services.delete');
    }
}

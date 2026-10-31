<?php

namespace App\Modules\Transformations\Policies;

use App\Models\User;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Transformations\Models\Transformation;

class TransformationPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('transformations.voir');
    }

    public function view(User $user, Transformation $transformation, Store $store): bool
    {
        return $transformation->boutique_id === $store->id && $user->can('transformations.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('transformations.creer');
    }

    public function cancel(User $user, Transformation $transformation, Store $store): bool
    {
        return $transformation->boutique_id === $store->id && $user->can('transformations.annuler');
    }
}

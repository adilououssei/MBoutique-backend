<?php

namespace App\Modules\Warranties\Policies;

use App\Models\User;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Warranties\Models\Warranty;

class WarrantyPolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('garanties.voir');
    }

    public function view(User $user, Warranty $warranty, Store $store): bool
    {
        return $warranty->boutique_id === $store->id && $user->can('garanties.voir');
    }

    public function manage(User $user, Warranty $warranty, Store $store): bool
    {
        return $warranty->boutique_id === $store->id && $user->can('garanties.gerer');
    }
}

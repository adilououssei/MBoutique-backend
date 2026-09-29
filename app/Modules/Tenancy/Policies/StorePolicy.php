<?php

namespace App\Modules\Tenancy\Policies;

use App\Models\User;
use App\Modules\Tenancy\Enums\StoreUserStatus;
use App\Modules\Tenancy\Models\Store;

/**
 * Store-level authorization: membership (Couche 1/3 of
 * docs/multi-tenancy.md) plus spatie permissions checked separately by
 * the controller/middleware. This policy answers "does this user belong
 * to this store at all", not "what may they do there".
 */
class StorePolicy
{
    public function view(User $user, Store $store): bool
    {
        return $this->isActiveMember($user, $store);
    }

    public function viewMembers(User $user, Store $store): bool
    {
        return $this->isActiveMember($user, $store) && $user->can('membres.voir');
    }

    public function manageMembers(User $user, Store $store): bool
    {
        return $this->isActiveMember($user, $store) && $user->can('membres.gerer');
    }

    private function isActiveMember(User $user, Store $store): bool
    {
        // Deliberately NOT using withoutStoreScope(): if TenantContext ever
        // disagreed with $store (a bug elsewhere), the extra global-scope
        // filter makes the query fail closed (no match) instead of masking
        // the inconsistency.
        return $store->storeUsers()
            ->where('utilisateur_id', $user->id)
            ->where('statut', StoreUserStatus::Active->value)
            ->exists();
    }
}

<?php

namespace App\Modules\Tenancy\Policies;

use App\Models\User;
use App\Modules\Tenancy\Enums\BusinessUserRole;
use App\Modules\Tenancy\Models\Business;

/**
 * Business-level authorization, backed by BusinessUser — never spatie
 * (spatie is store-level only). See docs/permissions.md §7.
 */
class BusinessPolicy
{
    public function view(User $user, Business $business): bool
    {
        return $business->businessUsers()->where('user_id', $user->id)->exists();
    }

    public function createStore(User $user, Business $business): bool
    {
        return $business->businessUsers()
            ->where('user_id', $user->id)
            ->whereIn('role', [BusinessUserRole::Owner->value, BusinessUserRole::Admin->value])
            ->exists();
    }

    public function manageMembers(User $user, Business $business): bool
    {
        return $business->businessUsers()
            ->where('user_id', $user->id)
            ->where('role', BusinessUserRole::Owner->value)
            ->exists();
    }
}

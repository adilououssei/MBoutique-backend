<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Tenancy\Enums\StoreUserStatus;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreUser;
use Illuminate\Support\Facades\DB;

/**
 * Adds/removes a member on a Store. Assumes it always runs within a
 * request already scoped to this Store by ResolveStoreContext (TenantContext
 * already points at $store), so role assignment needs no manual context
 * juggling here — unlike StoreService, which acts on a Store from a
 * business-scoped route where the store isn't the request's tenant yet.
 */
class StoreMembershipService
{
    /**
     * Attaches (or re-activates) a platform user on a Store with a given
     * store-level role. Immediate activation, no email-accept step — a
     * true invite flow needs the Notifications module (see docs/roadmap.md
     * Phase 6) and is out of scope for this socle.
     */
    public function addMember(Store $store, User $targetUser, string $role, User $actor): StoreUser
    {
        return DB::transaction(function () use ($store, $targetUser, $role, $actor) {
            $storeUser = StoreUser::withoutStoreScope()
                ->where('store_id', $store->id)
                ->where('user_id', $targetUser->id)
                ->first();

            if ($storeUser) {
                $storeUser->update([
                    'status' => StoreUserStatus::Active,
                    'joined_at' => $storeUser->joined_at ?? now(),
                ]);
            } else {
                $storeUser = StoreUser::create([
                    'store_id' => $store->id,
                    'user_id' => $targetUser->id,
                    'status' => StoreUserStatus::Active,
                    'invited_by_user_id' => $actor->id,
                    'invited_at' => now(),
                    'joined_at' => now(),
                ]);
            }

            $targetUser->syncRoles([$role]);

            return $storeUser;
        });
    }

    public function removeMember(Store $store, User $targetUser): void
    {
        DB::transaction(function () use ($store, $targetUser) {
            StoreUser::where('store_id', $store->id)
                ->where('user_id', $targetUser->id)
                ->firstOrFail()
                ->update(['status' => StoreUserStatus::Revoked]);

            $targetUser->syncRoles([]);
        });
    }
}

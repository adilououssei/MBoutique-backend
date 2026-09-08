<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Authorization\Services\StoreRoleProvisioner;
use App\Modules\Authorization\Support\StoreRole;
use App\Modules\Subscriptions\Services\SubscriptionLimits;
use App\Modules\Tenancy\Enums\StoreUserStatus;
use App\Modules\Tenancy\Models\Business;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreUser;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Support\Facades\DB;

class StoreService
{
    public function __construct(
        private readonly StoreRoleProvisioner $roleProvisioner,
        private readonly TenantContextContract $tenantContext,
        private readonly SubscriptionLimits $subscriptionLimits,
    ) {}

    /**
     * Creating a Store, provisioning its role templates, and granting
     * every existing BusinessUser of the Business immediate access is one
     * atomic action — see docs/database.md §2 "à la création d'un Store".
     */
    public function createForBusiness(Business $business, array $data): Store
    {
        $this->subscriptionLimits->assertCanCreateStore($business);

        return DB::transaction(function () use ($business, $data) {
            // business_id is deliberately not $fillable — see the
            // identical note in BusinessService::createForOwner.
            $store = new Store($data);
            $store->forceFill(['business_id' => $business->id]);
            $store->save();

            $this->roleProvisioner->provisionFor($store);

            foreach ($business->businessUsers()->with('user')->get() as $businessUser) {
                $this->grantStoreAccess($store, $businessUser->user, $businessUser->role->value);
            }

            return $store;
        });
    }

    /**
     * The mirror operation: when a BusinessUser is added to a Business
     * that already has Stores, they get the same immediate access to
     * every existing Store — access to a Business's stores must not
     * depend on whether the Store or the BusinessUser row came first.
     */
    public function grantAccessToAllStores(Business $business, User $user, string $businessRole): void
    {
        foreach ($business->stores as $store) {
            $this->grantStoreAccess($store, $user, $businessRole);
        }
    }

    private function grantStoreAccess(Store $store, User $user, string $businessRole): void
    {
        $previousStoreId = $this->tenantContext->getStoreId();
        $this->tenantContext->setStoreId($store->id);

        try {
            StoreUser::updateOrCreate(
                ['store_id' => $store->id, 'user_id' => $user->id],
                ['status' => StoreUserStatus::Active, 'joined_at' => now()],
            );

            $user->assignRole(StoreRole::forBusinessRole($businessRole));
        } finally {
            $previousStoreId !== null
                ? $this->tenantContext->setStoreId($previousStoreId)
                : $this->tenantContext->clear();
        }
    }
}

<?php

namespace Tests\Unit\Shared;

use App\Models\User;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreUser;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Proves Couche 5 of docs/multi-tenancy.md (validation scopée) actually
 * closes the gap it's meant to close: `Rule::exists()` runs a raw query
 * builder query, bypassing Eloquent's global scopes entirely, which is
 * exactly why this explicit ->where('store_id', ...) is required rather
 * than relying on BelongsToStore.
 */
class TenantScopedRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_an_id_belonging_to_another_store(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        app(TenantContextContract::class)->setStoreId($storeA->id);
        StoreUser::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);

        app(TenantContextContract::class)->setStoreId($storeB->id);
        $storeUserInB = StoreUser::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);

        // Back to acting within Store A's context...
        app(TenantContextContract::class)->setStoreId($storeA->id);

        $validator = Validator::make(
            ['store_user_id' => $storeUserInB->id],
            ['store_user_id' => [TenantScopedRules::existsInCurrentStore('store_users', 'id')]],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_it_accepts_an_id_belonging_to_the_current_store(): void
    {
        $storeA = Store::factory()->create();

        app(TenantContextContract::class)->setStoreId($storeA->id);
        $storeUserInA = StoreUser::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);

        $validator = Validator::make(
            ['store_user_id' => $storeUserInA->id],
            ['store_user_id' => [TenantScopedRules::existsInCurrentStore('store_users', 'id')]],
        );

        $this->assertTrue($validator->passes());
    }
}

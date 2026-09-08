<?php

namespace Tests\Feature\Modules\Features;

use App\Models\User;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreUser;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * docs/feature-gate.md §"Feature vs Permission" — the two must never be
 * conflated. This registers a throwaway route using the exact middleware
 * chain a real business module route will use once one exists (Sales,
 * Products, ...), since no such route exists yet in this phase.
 */
class FeatureVersusPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'store', 'feature:sales', 'permission:store_users.manage'])
            ->prefix('api')
            ->get('_test/feature-and-permission/stores/{store}', fn () => response()->json(['success' => true]));
    }

    private function storeWithFeature(string $slug, bool $enabled): Store
    {
        $domain = BusinessDomain::factory()->create();
        $feature = Feature::factory()->create(['slug' => $slug]);
        DomainFeature::create(['business_domain_id' => $domain->id, 'feature_id' => $feature->id, 'is_default_enabled' => $enabled]);

        return Store::factory()->create(['business_domain_id' => $domain->id]);
    }

    private function grantPermission(Store $store, User $user, string $permission): void
    {
        $tenantContext = app(TenantContextContract::class);
        $previous = $tenantContext->getStoreId();
        $tenantContext->setStoreId($store->id);

        try {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        } finally {
            $previous !== null ? $tenantContext->setStoreId($previous) : $tenantContext->clear();
        }
    }

    public function test_feature_disabled_blocks_access_even_with_the_permission(): void
    {
        $store = $this->storeWithFeature('sales', enabled: false);
        $user = User::factory()->create();
        StoreUser::factory()->for($store)->for($user)->create(['status' => 'active']);
        $this->grantPermission($store, $user, 'store_users.manage');
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/_test/feature-and-permission/stores/{$store->id}");

        $response->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function test_feature_enabled_but_missing_permission_still_blocks_access(): void
    {
        $store = $this->storeWithFeature('sales', enabled: true);
        $user = User::factory()->create();
        StoreUser::factory()->for($store)->for($user)->create(['status' => 'active']);
        Sanctum::actingAs($user);
        // No permission granted at all: the feature exists for this
        // store, but this user individually may not use it.

        $response = $this->getJson("/api/_test/feature-and-permission/stores/{$store->id}");

        $response->assertStatus(403);
        $this->assertNotSame('FEATURE_DISABLED', $response->json('code'));
    }

    public function test_feature_enabled_and_permission_granted_allows_access(): void
    {
        $store = $this->storeWithFeature('sales', enabled: true);
        $user = User::factory()->create();
        StoreUser::factory()->for($store)->for($user)->create(['status' => 'active']);
        $this->grantPermission($store, $user, 'store_users.manage');
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/_test/feature-and-permission/stores/{$store->id}");

        $response->assertStatus(200);
    }
}

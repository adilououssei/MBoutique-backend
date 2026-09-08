<?php

namespace Tests\Feature\Modules\Catalog;

use App\Modules\Features\Models\Feature;
use App\Modules\Features\Models\StoreFeatureOverride;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/**
 * docs/catalog.md §"FeatureGate": the Catalog must never assume a
 * feature is enabled for every store — a hair_salon-like domain that
 * never enabled `products` must be blocked at the feature layer, even
 * for the store's own owner (who has every permission).
 */
class CatalogFeatureGateTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    public function test_products_are_reachable_when_the_domain_enables_the_feature(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $this->getJson("/api/stores/{$store->id}/products")->assertStatus(200);
    }

    public function test_products_are_blocked_when_the_domain_never_enabled_the_feature(): void
    {
        // Mirrors a hair_salon-like domain: only 'services' is enabled.
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/stores/{$store->id}/products");

        $response->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function test_the_owner_permission_does_not_bypass_a_disabled_feature(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($owner);

        // The owner role has products.create, yet the feature itself is
        // off for this store's domain — FeatureGate wins regardless.
        $this->postJson("/api/stores/{$store->id}/products", ['name' => 'Article', 'selling_price' => 100])
            ->assertStatus(403)
            ->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function test_a_store_override_can_enable_a_feature_its_domain_does_not_include(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        // 'products' is deliberately NOT in this store's domain — it must
        // still exist as a Feature row (globally active) for the store to
        // be able to override it on.
        $productsFeature = Feature::firstOrCreate(['slug' => 'products'], ['name' => 'products']);

        app(TenantContextContract::class)->setStoreId($store->id);
        StoreFeatureOverride::create(['feature_id' => $productsFeature->id, 'is_enabled' => true]);
        app(TenantContextContract::class)->clear();

        Sanctum::actingAs($owner);

        $this->getJson("/api/stores/{$store->id}/products")->assertStatus(200);
    }
}

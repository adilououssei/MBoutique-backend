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
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/produits")->assertStatus(200);
    }

    public function test_products_are_blocked_when_the_domain_never_enabled_the_feature(): void
    {
        // Mirrors a hair_salon-like domain: only 'services' is enabled.
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/boutiques/{$store->id}/produits");

        $response->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');
    }

    public function test_the_owner_permission_does_not_bypass_a_disabled_feature(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($owner);

        // The owner role has products.create, yet the feature itself is
        // off for this store's domain — FeatureGate wins regardless.
        $this->postJson("/api/boutiques/{$store->id}/produits", ['nom' => 'Article', 'vente_detail_active' => true, 'prix_detail' => 100])
            ->assertStatus(403)
            ->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');
    }

    public function test_a_store_override_can_enable_a_feature_its_domain_does_not_include(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        // 'produits' is deliberately NOT in this store's domain — it must
        // still exist as a Feature row (globally active) for the store to
        // be able to override it on.
        $productsFeature = Feature::firstOrCreate(['slug' => 'produits'], ['nom' => 'produits']);

        app(TenantContextContract::class)->setStoreId($store->id);
        StoreFeatureOverride::create(['fonctionnalite_id' => $productsFeature->id, 'activee' => true]);
        app(TenantContextContract::class)->clear();

        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/produits")->assertStatus(200);
    }
}

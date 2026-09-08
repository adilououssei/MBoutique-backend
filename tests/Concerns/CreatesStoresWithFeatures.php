<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use App\Modules\Tenancy\Models\Store;
use Laravel\Sanctum\Sanctum;

/**
 * Shared by the Catalog test suites: builds a Store whose domain enables
 * exactly the given features, through the real HTTP business/store
 * creation flow (Phase 1/2) rather than re-implementing role
 * provisioning by hand.
 */
trait CreatesStoresWithFeatures
{
    /** @return array{owner: User, store: Store, businessId: int} */
    protected function createStoreWithFeatures(array $enabledFeatureSlugs): array
    {
        $domain = BusinessDomain::factory()->create();

        foreach ($enabledFeatureSlugs as $slug) {
            $feature = Feature::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            DomainFeature::create([
                'business_domain_id' => $domain->id,
                'feature_id' => $feature->id,
                'is_default_enabled' => true,
            ]);
        }

        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $businessId = $this->postJson('/api/businesses', ['name' => 'Boutique Test'])->json('data.id');
        $storeId = $this->postJson("/api/businesses/{$businessId}/stores", [
            'name' => 'Store Test',
            'business_domain_id' => $domain->id,
        ])->json('data.id');

        return ['owner' => $owner, 'store' => Store::findOrFail($storeId), 'businessId' => $businessId];
    }
}

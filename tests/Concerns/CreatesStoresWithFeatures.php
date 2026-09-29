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
    /** @return array{proprietaire: User, store: Store, businessId: int} */
    protected function createStoreWithFeatures(array $enabledFeatureSlugs): array
    {
        $domain = BusinessDomain::factory()->create();

        foreach ($enabledFeatureSlugs as $slug) {
            $feature = Feature::firstOrCreate(['slug' => $slug], ['nom' => $slug]);
            DomainFeature::create([
                'domaine_activite_id' => $domain->id,
                'fonctionnalite_id' => $feature->id,
                'active_par_defaut' => true,
            ]);
        }

        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $businessId = $this->postJson('/api/entreprises', ['nom' => 'Boutique Test'])->json('donnees.id');
        $storeId = $this->postJson("/api/entreprises/{$businessId}/boutiques", [
            'nom' => 'Store Test',
            'domaine_activite_id' => $domain->id,
        ])->json('donnees.id');

        return ['proprietaire' => $owner, 'store' => Store::findOrFail($storeId), 'businessId' => $businessId];
    }
}

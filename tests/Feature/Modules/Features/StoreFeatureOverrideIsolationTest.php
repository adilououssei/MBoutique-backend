<?php

namespace Tests\Feature\Modules\Features;

use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use App\Modules\Features\Models\StoreFeatureOverride;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/features.md §5 / the audit: overrides are tenant-scoped data
 * (BelongsToStore) exactly like any other Store-owned row — Store A must
 * never see or affect Store B's overrides.
 */
class StoreFeatureOverrideIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_a_cannot_see_store_bs_override_in_a_scoped_query(): void
    {
        $domain = BusinessDomain::factory()->create();
        $feature = Feature::factory()->create();
        $storeA = Store::factory()->create(['domaine_activite_id' => $domain->id]);
        $storeB = Store::factory()->create(['domaine_activite_id' => $domain->id]);

        app(TenantContextContract::class)->setStoreId($storeB->id);
        StoreFeatureOverride::create(['fonctionnalite_id' => $feature->id, 'activee' => false]);

        app(TenantContextContract::class)->setStoreId($storeA->id);

        $this->assertCount(0, StoreFeatureOverride::all());
        $this->assertCount(1, StoreFeatureOverride::withoutStoreScope()->get());
    }

    public function test_store_bs_override_can_never_leak_into_store_as_feature_resolution(): void
    {
        $domain = BusinessDomain::factory()->create();
        $feature = Feature::factory()->create(['slug' => 'stock']);
        DomainFeature::create([
            'domaine_activite_id' => $domain->id,
            'fonctionnalite_id' => $feature->id,
            'active_par_defaut' => true,
        ]);
        $storeA = Store::factory()->create(['domaine_activite_id' => $domain->id]);
        $storeB = Store::factory()->create(['domaine_activite_id' => $domain->id]);

        // Store B explicitly disables inventory; Store A never touched it.
        app(TenantContextContract::class)->setStoreId($storeB->id);
        StoreFeatureOverride::create(['fonctionnalite_id' => $feature->id, 'activee' => false]);

        $gate = app(FeatureGate::class);

        $this->assertTrue($gate->allows($storeA->fresh(), 'stock'), 'Store A keeps the domain default');
        $this->assertFalse($gate->allows($storeB->fresh(), 'stock'), 'Store B kept its own override');
    }

    public function test_an_override_created_while_scoped_to_store_a_can_never_be_attached_to_store_b(): void
    {
        $domain = BusinessDomain::factory()->create();
        $feature = Feature::factory()->create();
        $storeA = Store::factory()->create(['domaine_activite_id' => $domain->id]);
        $storeB = Store::factory()->create(['domaine_activite_id' => $domain->id]);

        app(TenantContextContract::class)->setStoreId($storeA->id);

        $override = StoreFeatureOverride::create([
            'boutique_id' => $storeB->id, // attacker-controlled value, must be ignored
            'fonctionnalite_id' => $feature->id,
            'activee' => false,
        ]);

        $this->assertSame($storeA->id, $override->boutique_id);
    }
}

<?php

namespace Tests\Unit\Modules\Features;

use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use App\Modules\Features\Models\FeatureDependency;
use App\Modules\Features\Models\StoreFeatureOverride;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeFeature(string $slug): Feature
    {
        return Feature::factory()->create(['slug' => $slug]);
    }

    private function makeDomainWithFeatures(string $slug, array $enabledSlugs, array $allSlugs): BusinessDomain
    {
        $domain = BusinessDomain::factory()->create(['slug' => $slug]);

        foreach ($allSlugs as $featureSlug) {
            $feature = Feature::firstOrCreate(['slug' => $featureSlug], ['nom' => $featureSlug]);
            DomainFeature::create([
                'domaine_activite_id' => $domain->id,
                'fonctionnalite_id' => $feature->id,
                'active_par_defaut' => in_array($featureSlug, $enabledSlugs, true),
            ]);
        }

        return $domain;
    }

    public function test_hair_salon_domain_resolves_the_expected_features(): void
    {
        $domain = $this->makeDomainWithFeatures(
            'coiffure',
            enabledSlugs: ['services', 'rendez_vous', 'clients', 'employes', 'ventes', 'caisse'],
            allSlugs: ['services', 'rendez_vous', 'clients', 'employes', 'ventes', 'caisse', 'stock'],
        );
        $store = Store::factory()->create(['domaine_activite_id' => $domain->id]);

        $gate = app(FeatureGate::class);

        $this->assertTrue($gate->allows($store, 'services'));
        $this->assertTrue($gate->allows($store, 'rendez_vous'));
        $this->assertFalse($gate->allows($store, 'stock'));
    }

    public function test_general_store_domain_resolves_the_expected_features(): void
    {
        $domain = $this->makeDomainWithFeatures(
            'alimentation_generale',
            enabledSlugs: ['produits', 'stock', 'ventes', 'caisse', 'clients', 'fournisseurs', 'employes'],
            allSlugs: ['produits', 'stock', 'ventes', 'caisse', 'clients', 'fournisseurs', 'employes', 'rendez_vous'],
        );
        $store = Store::factory()->create(['domaine_activite_id' => $domain->id]);

        $gate = app(FeatureGate::class);

        $this->assertTrue($gate->allows($store, 'produits'));
        $this->assertTrue($gate->allows($store, 'stock'));
        $this->assertFalse($gate->allows($store, 'rendez_vous'));
    }

    public function test_a_feature_absent_from_the_domain_mapping_defaults_to_disabled(): void
    {
        $domain = BusinessDomain::factory()->create();
        $this->makeFeature('unmapped_feature');
        $store = Store::factory()->create(['domaine_activite_id' => $domain->id]);

        $this->assertFalse(app(FeatureGate::class)->allows($store, 'unmapped_feature'));
    }

    public function test_a_globally_inactive_feature_is_never_allowed_even_if_the_domain_includes_it(): void
    {
        $domain = BusinessDomain::factory()->create();
        $feature = Feature::factory()->inactive()->create(['slug' => 'produits']);
        DomainFeature::create(['domaine_activite_id' => $domain->id, 'fonctionnalite_id' => $feature->id, 'active_par_defaut' => true]);
        $store = Store::factory()->create(['domaine_activite_id' => $domain->id]);

        $this->assertFalse(app(FeatureGate::class)->allows($store, 'produits'));
    }

    public function test_a_store_override_can_disable_a_feature_the_domain_enables_by_default(): void
    {
        $domain = $this->makeDomainWithFeatures('restaurant', ['stock'], ['stock']);
        $store = Store::factory()->create(['domaine_activite_id' => $domain->id]);
        $feature = Feature::where('slug', 'stock')->firstOrFail();

        $this->assertTrue(app(FeatureGate::class)->allows($store, 'stock'), 'sanity: enabled by domain before override');

        app(TenantContextContract::class)->setStoreId($store->id);
        StoreFeatureOverride::create(['fonctionnalite_id' => $feature->id, 'activee' => false]);

        $this->assertFalse(app(FeatureGate::class)->allows($store->fresh(), 'stock'));
    }

    public function test_a_store_override_can_enable_a_feature_the_domain_does_not_include(): void
    {
        $domain = BusinessDomain::factory()->create();
        $feature = $this->makeFeature('rendez_vous');
        $store = Store::factory()->create(['domaine_activite_id' => $domain->id]);

        $this->assertFalse(app(FeatureGate::class)->allows($store, 'rendez_vous'), 'sanity: not in domain defaults');

        app(TenantContextContract::class)->setStoreId($store->id);
        StoreFeatureOverride::create(['fonctionnalite_id' => $feature->id, 'activee' => true]);

        $this->assertTrue(app(FeatureGate::class)->allows($store->fresh(), 'rendez_vous'));
    }

    public function test_a_feature_cannot_be_active_if_a_dependency_is_not(): void
    {
        $domain = $this->makeDomainWithFeatures('hair_salon_no_services', ['rendez_vous', 'employes'], ['rendez_vous', 'services', 'employes']);
        $store = Store::factory()->create(['domaine_activite_id' => $domain->id]);

        // 'rendez_vous' is enabled by the domain, but its dependency
        // 'services' is not (only 'rendez_vous' and 'employes' are
        // enabled above) — see docs/features.md §7.
        FeatureDependency::create([
            'fonctionnalite_id' => Feature::where('slug', 'rendez_vous')->value('id'),
            'depend_de_fonctionnalite_id' => Feature::where('slug', 'services')->value('id'),
        ]);

        $this->assertFalse(app(FeatureGate::class)->allows($store, 'rendez_vous'));
    }

    public function test_a_subscription_plan_can_restrict_a_feature_the_domain_would_otherwise_enable(): void
    {
        $domain = $this->makeDomainWithFeatures('restaurant', ['commandes', 'tables'], ['commandes', 'tables']);
        $store = Store::factory()->create(['domaine_activite_id' => $domain->id]);

        $plan = Plan::factory()->create();
        $ordersFeature = Feature::where('slug', 'commandes')->firstOrFail();
        $plan->features()->attach($ordersFeature->id); // plan only lists 'commandes', not 'tables'

        $subscription = new Subscription(['forfait_id' => $plan->id]);
        $subscription->forceFill(['entreprise_id' => $store->entreprise_id]);
        $subscription->save();

        $gate = app(FeatureGate::class);

        $this->assertTrue($gate->allows($store->fresh(), 'commandes'), 'listed by the plan, allowed');
        $this->assertFalse($gate->allows($store->fresh(), 'tables'), 'not listed by the plan, blocked despite domain default');
    }

    public function test_a_business_without_a_subscription_is_not_restricted_by_plan(): void
    {
        $domain = $this->makeDomainWithFeatures('alimentation_generale', ['produits'], ['produits']);
        $store = Store::factory()->create(['domaine_activite_id' => $domain->id]);

        // No Subscription row at all for this Business — must fail open,
        // not closed (see FeatureGate::planFeatureIds doc block).
        $this->assertTrue(app(FeatureGate::class)->allows($store, 'produits'));
    }

    public function test_a_plan_with_no_listed_features_does_not_restrict_anything(): void
    {
        $domain = $this->makeDomainWithFeatures('alimentation_generale', ['produits'], ['produits']);
        $store = Store::factory()->create(['domaine_activite_id' => $domain->id]);

        $plan = Plan::factory()->create(); // no ->features() attached at all
        $subscription = new Subscription(['forfait_id' => $plan->id]);
        $subscription->forceFill(['entreprise_id' => $store->entreprise_id]);
        $subscription->save();

        $this->assertTrue(app(FeatureGate::class)->allows($store->fresh(), 'produits'));
    }
}

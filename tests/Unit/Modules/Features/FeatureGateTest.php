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
            $feature = Feature::firstOrCreate(['slug' => $featureSlug], ['name' => $featureSlug]);
            DomainFeature::create([
                'business_domain_id' => $domain->id,
                'feature_id' => $feature->id,
                'is_default_enabled' => in_array($featureSlug, $enabledSlugs, true),
            ]);
        }

        return $domain;
    }

    public function test_hair_salon_domain_resolves_the_expected_features(): void
    {
        $domain = $this->makeDomainWithFeatures(
            'hair_salon',
            enabledSlugs: ['services', 'appointments', 'customers', 'employees', 'sales', 'cash_register'],
            allSlugs: ['services', 'appointments', 'customers', 'employees', 'sales', 'cash_register', 'inventory'],
        );
        $store = Store::factory()->create(['business_domain_id' => $domain->id]);

        $gate = app(FeatureGate::class);

        $this->assertTrue($gate->allows($store, 'services'));
        $this->assertTrue($gate->allows($store, 'appointments'));
        $this->assertFalse($gate->allows($store, 'inventory'));
    }

    public function test_general_store_domain_resolves_the_expected_features(): void
    {
        $domain = $this->makeDomainWithFeatures(
            'general_store',
            enabledSlugs: ['products', 'inventory', 'sales', 'cash_register', 'customers', 'suppliers', 'employees'],
            allSlugs: ['products', 'inventory', 'sales', 'cash_register', 'customers', 'suppliers', 'employees', 'appointments'],
        );
        $store = Store::factory()->create(['business_domain_id' => $domain->id]);

        $gate = app(FeatureGate::class);

        $this->assertTrue($gate->allows($store, 'products'));
        $this->assertTrue($gate->allows($store, 'inventory'));
        $this->assertFalse($gate->allows($store, 'appointments'));
    }

    public function test_a_feature_absent_from_the_domain_mapping_defaults_to_disabled(): void
    {
        $domain = BusinessDomain::factory()->create();
        $this->makeFeature('unmapped_feature');
        $store = Store::factory()->create(['business_domain_id' => $domain->id]);

        $this->assertFalse(app(FeatureGate::class)->allows($store, 'unmapped_feature'));
    }

    public function test_a_globally_inactive_feature_is_never_allowed_even_if_the_domain_includes_it(): void
    {
        $domain = BusinessDomain::factory()->create();
        $feature = Feature::factory()->inactive()->create(['slug' => 'products']);
        DomainFeature::create(['business_domain_id' => $domain->id, 'feature_id' => $feature->id, 'is_default_enabled' => true]);
        $store = Store::factory()->create(['business_domain_id' => $domain->id]);

        $this->assertFalse(app(FeatureGate::class)->allows($store, 'products'));
    }

    public function test_a_store_override_can_disable_a_feature_the_domain_enables_by_default(): void
    {
        $domain = $this->makeDomainWithFeatures('restaurant', ['inventory'], ['inventory']);
        $store = Store::factory()->create(['business_domain_id' => $domain->id]);
        $feature = Feature::where('slug', 'inventory')->firstOrFail();

        $this->assertTrue(app(FeatureGate::class)->allows($store, 'inventory'), 'sanity: enabled by domain before override');

        app(TenantContextContract::class)->setStoreId($store->id);
        StoreFeatureOverride::create(['feature_id' => $feature->id, 'is_enabled' => false]);

        $this->assertFalse(app(FeatureGate::class)->allows($store->fresh(), 'inventory'));
    }

    public function test_a_store_override_can_enable_a_feature_the_domain_does_not_include(): void
    {
        $domain = BusinessDomain::factory()->create();
        $feature = $this->makeFeature('appointments');
        $store = Store::factory()->create(['business_domain_id' => $domain->id]);

        $this->assertFalse(app(FeatureGate::class)->allows($store, 'appointments'), 'sanity: not in domain defaults');

        app(TenantContextContract::class)->setStoreId($store->id);
        StoreFeatureOverride::create(['feature_id' => $feature->id, 'is_enabled' => true]);

        $this->assertTrue(app(FeatureGate::class)->allows($store->fresh(), 'appointments'));
    }

    public function test_a_feature_cannot_be_active_if_a_dependency_is_not(): void
    {
        $domain = $this->makeDomainWithFeatures('hair_salon_no_services', ['appointments', 'employees'], ['appointments', 'services', 'employees']);
        $store = Store::factory()->create(['business_domain_id' => $domain->id]);

        // 'appointments' is enabled by the domain, but its dependency
        // 'services' is not (only 'appointments' and 'employees' are
        // enabled above) — see docs/features.md §7.
        FeatureDependency::create([
            'feature_id' => Feature::where('slug', 'appointments')->value('id'),
            'depends_on_feature_id' => Feature::where('slug', 'services')->value('id'),
        ]);

        $this->assertFalse(app(FeatureGate::class)->allows($store, 'appointments'));
    }

    public function test_a_subscription_plan_can_restrict_a_feature_the_domain_would_otherwise_enable(): void
    {
        $domain = $this->makeDomainWithFeatures('restaurant', ['orders', 'tables'], ['orders', 'tables']);
        $store = Store::factory()->create(['business_domain_id' => $domain->id]);

        $plan = Plan::factory()->create();
        $ordersFeature = Feature::where('slug', 'orders')->firstOrFail();
        $plan->features()->attach($ordersFeature->id); // plan only lists 'orders', not 'tables'

        $subscription = new Subscription(['plan_id' => $plan->id]);
        $subscription->forceFill(['business_id' => $store->business_id]);
        $subscription->save();

        $gate = app(FeatureGate::class);

        $this->assertTrue($gate->allows($store->fresh(), 'orders'), 'listed by the plan, allowed');
        $this->assertFalse($gate->allows($store->fresh(), 'tables'), 'not listed by the plan, blocked despite domain default');
    }

    public function test_a_business_without_a_subscription_is_not_restricted_by_plan(): void
    {
        $domain = $this->makeDomainWithFeatures('general_store', ['products'], ['products']);
        $store = Store::factory()->create(['business_domain_id' => $domain->id]);

        // No Subscription row at all for this Business — must fail open,
        // not closed (see FeatureGate::planFeatureIds doc block).
        $this->assertTrue(app(FeatureGate::class)->allows($store, 'products'));
    }

    public function test_a_plan_with_no_listed_features_does_not_restrict_anything(): void
    {
        $domain = $this->makeDomainWithFeatures('general_store', ['products'], ['products']);
        $store = Store::factory()->create(['business_domain_id' => $domain->id]);

        $plan = Plan::factory()->create(); // no ->features() attached at all
        $subscription = new Subscription(['plan_id' => $plan->id]);
        $subscription->forceFill(['business_id' => $store->business_id]);
        $subscription->save();

        $this->assertTrue(app(FeatureGate::class)->allows($store->fresh(), 'products'));
    }
}

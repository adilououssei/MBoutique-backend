<?php

namespace Tests\Unit\Modules\Subscriptions;

use App\Models\User;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Subscriptions\Exceptions\SubscriptionLimitExceededException;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\SubscriptionLimits;
use App\Modules\Tenancy\Models\Business;
use App\Modules\Tenancy\Models\BusinessUser;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/subscriptions.md §3 / §12 of the Phase 2 brief: a Limit (a count)
 * is a different concept from a Feature (on/off) — this suite exercises
 * the Limit side only; FeatureGateTest exercises the Feature side.
 */
class SubscriptionLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function businessWithPlan(?int $maxStores): Business
    {
        $business = Business::factory()->create();
        $plan = Plan::factory()->create(['max_boutiques' => $maxStores]);
        $subscription = new Subscription(['forfait_id' => $plan->id]);
        $subscription->forceFill(['entreprise_id' => $business->id]);
        $subscription->save();

        return $business;
    }

    public function test_a_business_within_its_plans_store_limit_can_create_another(): void
    {
        $business = $this->businessWithPlan(maxStores: 2);
        Store::factory()->create(['entreprise_id' => $business->id]);

        app(SubscriptionLimits::class)->assertCanCreateStore($business); // no exception

        $this->assertTrue(true);
    }

    public function test_a_business_at_its_plans_store_limit_cannot_create_another(): void
    {
        $business = $this->businessWithPlan(maxStores: 1);
        Store::factory()->create(['entreprise_id' => $business->id]);

        $this->expectException(SubscriptionLimitExceededException::class);

        app(SubscriptionLimits::class)->assertCanCreateStore($business);
    }

    public function test_a_plan_with_an_unlimited_store_count_never_blocks_creation(): void
    {
        $business = $this->businessWithPlan(maxStores: null);
        Store::factory()->count(5)->create(['entreprise_id' => $business->id]);

        app(SubscriptionLimits::class)->assertCanCreateStore($business);

        $this->assertTrue(true);
    }

    public function test_a_business_without_any_subscription_is_not_limited(): void
    {
        $business = Business::factory()->create(); // no Subscription row at all

        app(SubscriptionLimits::class)->assertCanCreateStore($business);

        $this->assertTrue(true);
    }

    public function test_the_http_endpoint_rejects_store_creation_beyond_the_plan_limit(): void
    {
        $business = $this->businessWithPlan(maxStores: 1);
        Store::factory()->create(['entreprise_id' => $business->id]);

        $owner = User::factory()->create();
        BusinessUser::create([
            'entreprise_id' => $business->id,
            'utilisateur_id' => $owner->id,
            'role' => 'proprietaire',
        ]);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/entreprises/{$business->id}/boutiques", [
            'nom' => 'Boutique de trop',
            'domaine_activite_id' => BusinessDomain::factory()->create()->id,
        ]);

        $response->assertStatus(403)->assertJsonPath('code', 'LIMITE_ABONNEMENT_ATTEINTE');
    }
}

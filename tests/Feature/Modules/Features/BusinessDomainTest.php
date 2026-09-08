<?php

namespace Tests\Feature\Modules\Features;

use App\Models\User;
use App\Modules\Features\Models\BusinessDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * No admin HTTP surface exists yet for domains (deferred to Phase 6 per
 * docs/roadmap.md — see docs/feature-gate.md §"Administration"), so
 * creation/activation is exercised at the model level, and the one
 * user-facing consequence (an inactive domain can't be picked for a new
 * store) is exercised through the real store-creation endpoint.
 */
class BusinessDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_business_domain_can_be_created(): void
    {
        $domain = BusinessDomain::factory()->create(['name' => 'Coiffure', 'slug' => 'hair_salon']);

        $this->assertDatabaseHas('business_domains', ['slug' => 'hair_salon', 'is_active' => true]);
        $this->assertTrue($domain->is_active);
    }

    public function test_a_business_domain_can_be_deactivated(): void
    {
        $domain = BusinessDomain::factory()->create();

        $domain->update(['is_active' => false]);

        $this->assertFalse($domain->fresh()->is_active);
    }

    public function test_an_inactive_domain_cannot_be_selected_for_a_new_store(): void
    {
        $inactiveDomain = BusinessDomain::factory()->inactive()->create();
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $businessId = $this->postJson('/api/businesses', ['name' => 'Boutique'])->json('data.id');

        $response = $this->postJson("/api/businesses/{$businessId}/stores", [
            'name' => 'Ma boutique',
            'business_domain_id' => $inactiveDomain->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('business_domain_id');
    }

    public function test_an_active_domain_can_be_selected_for_a_new_store(): void
    {
        $domain = BusinessDomain::factory()->create();
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $businessId = $this->postJson('/api/businesses', ['name' => 'Boutique'])->json('data.id');

        $response = $this->postJson("/api/businesses/{$businessId}/stores", [
            'name' => 'Ma boutique',
            'business_domain_id' => $domain->id,
        ]);

        $response->assertStatus(201);
    }
}

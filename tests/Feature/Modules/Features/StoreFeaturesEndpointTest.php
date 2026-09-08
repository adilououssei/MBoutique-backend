<?php

namespace Tests\Feature\Modules\Features;

use App\Models\User;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreFeaturesEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_store_member_can_fetch_the_resolved_feature_list(): void
    {
        $domain = BusinessDomain::factory()->create(['slug' => 'hair_salon', 'name' => 'Coiffure']);
        $enabled = Feature::factory()->create(['slug' => 'services']);
        $disabled = Feature::factory()->create(['slug' => 'inventory']);
        DomainFeature::create(['business_domain_id' => $domain->id, 'feature_id' => $enabled->id, 'is_default_enabled' => true]);
        DomainFeature::create(['business_domain_id' => $domain->id, 'feature_id' => $disabled->id, 'is_default_enabled' => false]);

        $store = Store::factory()->create(['business_domain_id' => $domain->id]);
        $user = User::factory()->create();
        StoreUser::factory()->for($store)->for($user)->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/stores/{$store->id}/features");

        $response->assertStatus(200)
            ->assertJsonPath('data.domain.slug', 'hair_salon')
            ->assertJsonPath('data.domain.name', 'Coiffure');

        $features = collect($response->json('data.features'))->keyBy('slug');
        $this->assertTrue($features['services']['enabled']);
        $this->assertFalse($features['inventory']['enabled']);
    }

    public function test_a_non_member_cannot_fetch_another_stores_feature_list(): void
    {
        $store = Store::factory()->create();
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);

        $this->getJson("/api/stores/{$store->id}/features")->assertStatus(404);
    }
}

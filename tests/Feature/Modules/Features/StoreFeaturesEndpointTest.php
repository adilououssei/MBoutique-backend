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
        $domain = BusinessDomain::factory()->create(['slug' => 'coiffure', 'nom' => 'Coiffure']);
        $enabled = Feature::factory()->create(['slug' => 'services']);
        $disabled = Feature::factory()->create(['slug' => 'stock']);
        DomainFeature::create(['domaine_activite_id' => $domain->id, 'fonctionnalite_id' => $enabled->id, 'active_par_defaut' => true]);
        DomainFeature::create(['domaine_activite_id' => $domain->id, 'fonctionnalite_id' => $disabled->id, 'active_par_defaut' => false]);

        $store = Store::factory()->create(['domaine_activite_id' => $domain->id]);
        $user = User::factory()->create();
        StoreUser::factory()->for($store)->for($user)->create(['statut' => 'actif']);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/boutiques/{$store->id}/fonctionnalites");

        $response->assertStatus(200)
            ->assertJsonPath('donnees.domaine.slug', 'coiffure')
            ->assertJsonPath('donnees.domaine.nom', 'Coiffure');

        $features = collect($response->json('donnees.fonctionnalites'))->keyBy('slug');
        $this->assertTrue($features['services']['activee']);
        $this->assertFalse($features['stock']['activee']);
    }

    public function test_a_non_member_cannot_fetch_another_stores_feature_list(): void
    {
        $store = Store::factory()->create();
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);

        $this->getJson("/api/boutiques/{$store->id}/fonctionnalites")->assertStatus(404);
    }
}

<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Models\User;
use App\Modules\Features\Models\BusinessDomain;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    private function createBusiness(User $user): int
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/entreprises', ['nom' => 'Boutique Aïcha'])->json('donnees.id');
    }

    private function createStore(int $businessId, string $name = 'Riz & Co', ?int $domainId = null)
    {
        return $this->postJson("/api/entreprises/{$businessId}/boutiques", [
            'nom' => $name,
            'domaine_activite_id' => $domainId ?? BusinessDomain::factory()->create()->id,
        ]);
    }

    public function test_a_business_owner_can_create_a_store(): void
    {
        $owner = User::factory()->create();
        $businessId = $this->createBusiness($owner);

        $response = $this->createStore($businessId, 'Riz & Co');

        $response->assertStatus(201)
            ->assertJsonPath('donnees.nom', 'Riz & Co')
            ->assertJsonStructure(['donnees' => ['domaine_activite' => ['slug', 'nom']]]);

        $this->assertDatabaseHas('utilisateurs_boutique', [
            'boutique_id' => $response->json('donnees.id'),
            'utilisateur_id' => $owner->id,
            'statut' => 'actif',
        ]);
    }

    public function test_creating_a_store_without_a_domain_is_rejected(): void
    {
        $owner = User::factory()->create();
        $businessId = $this->createBusiness($owner);

        $this->postJson("/api/entreprises/{$businessId}/boutiques", ['nom' => 'Riz & Co'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domaine_activite_id', 'erreurs');
    }

    public function test_creating_a_store_with_an_inactive_domain_is_rejected(): void
    {
        $owner = User::factory()->create();
        $businessId = $this->createBusiness($owner);
        $inactiveDomain = BusinessDomain::factory()->inactive()->create();

        $this->createStore($businessId, 'Riz & Co', $inactiveDomain->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('domaine_activite_id', 'erreurs');
    }

    public function test_creating_a_store_grants_the_owner_role_immediately(): void
    {
        $owner = User::factory()->create();
        $businessId = $this->createBusiness($owner);
        $storeId = $this->createStore($businessId)->json('donnees.id');

        // hasRole() is team-scoped: outside of a request scoped to this
        // store by the 'store' middleware, TenantContext must be pointed
        // at it manually before checking — see docs/permissions.md §5 bis.
        app(TenantContextContract::class)->setStoreId($storeId);

        $this->assertTrue($owner->fresh()->hasRole('proprietaire'));
    }

    public function test_a_user_with_no_business_membership_cannot_create_a_store(): void
    {
        $owner = User::factory()->create();
        $businessId = $this->createBusiness($owner);

        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);

        $this->createStore($businessId, 'Riz & Co')->assertStatus(403);
    }

    public function test_a_user_can_belong_to_multiple_stores(): void
    {
        $owner = User::factory()->create();
        $businessId = $this->createBusiness($owner);

        $this->createStore($businessId, 'Store 1');
        $this->createStore($businessId, 'Store 2');

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/boutiques');

        $response->assertStatus(200)->assertJsonCount(2, 'donnees');
    }

    public function test_a_second_business_user_gets_immediate_access_to_existing_stores(): void
    {
        $owner = User::factory()->create();
        $businessId = $this->createBusiness($owner);
        $storeId = $this->createStore($businessId)->json('donnees.id');

        $admin = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/entreprises/{$businessId}/utilisateurs", [
            'email' => $admin->email,
            'role' => 'administrateur',
        ])->assertStatus(201);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/boutiques');

        $response->assertStatus(200)->assertJsonCount(1, 'donnees');

        app(TenantContextContract::class)->setStoreId($storeId);
        $this->assertTrue($admin->fresh()->hasRole('administrateur'));
    }
}

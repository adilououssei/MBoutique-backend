<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Models\User;
use App\Modules\Features\Models\BusinessDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreMembershipTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{owner: User, storeId: int} */
    private function createOwnerAndStore(): array
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $businessId = $this->postJson('/api/entreprises', ['nom' => 'Boutique'])->json('donnees.id');
        $storeId = $this->postJson("/api/entreprises/{$businessId}/boutiques", [
            'nom' => 'Riz & Co',
            'domaine_activite_id' => BusinessDomain::factory()->create()->id,
        ])->json('donnees.id');

        return ['proprietaire' => $owner, 'storeId' => $storeId];
    }

    public function test_owner_can_add_a_member_with_a_role(): void
    {
        ['proprietaire' => $owner, 'storeId' => $storeId] = $this->createOwnerAndStore();
        $newMember = User::factory()->create();

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/boutiques/{$storeId}/membres", [
            'email' => $newMember->email,
            'role' => 'caissier',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('utilisateurs_boutique', [
            'boutique_id' => $storeId,
            'utilisateur_id' => $newMember->id,
            'statut' => 'actif',
        ]);
    }

    public function test_owner_can_list_members(): void
    {
        ['proprietaire' => $owner, 'storeId' => $storeId] = $this->createOwnerAndStore();
        $newMember = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$storeId}/membres", ['email' => $newMember->email, 'role' => 'caissier']);

        $response = $this->getJson("/api/boutiques/{$storeId}/membres");

        $response->assertStatus(200)->assertJsonCount(2, 'donnees');
    }

    public function test_a_cashier_cannot_add_members(): void
    {
        ['proprietaire' => $owner, 'storeId' => $storeId] = $this->createOwnerAndStore();
        $cashier = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$storeId}/membres", ['email' => $cashier->email, 'role' => 'caissier']);

        $intruder = User::factory()->create();
        Sanctum::actingAs($cashier);
        $response = $this->postJson("/api/boutiques/{$storeId}/membres", ['email' => $intruder->email, 'role' => 'employe']);

        $response->assertStatus(403)->assertJsonPath('code', 'ACCES_INTERDIT');
    }

    public function test_a_cashier_can_still_view_members(): void
    {
        ['proprietaire' => $owner, 'storeId' => $storeId] = $this->createOwnerAndStore();
        $cashier = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$storeId}/membres", ['email' => $cashier->email, 'role' => 'caissier']);

        Sanctum::actingAs($cashier);
        $this->getJson("/api/boutiques/{$storeId}/membres")->assertStatus(200);
    }

    public function test_owner_can_revoke_a_member(): void
    {
        ['proprietaire' => $owner, 'storeId' => $storeId] = $this->createOwnerAndStore();
        $member = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$storeId}/membres", ['email' => $member->email, 'role' => 'caissier']);

        $response = $this->deleteJson("/api/boutiques/{$storeId}/membres/{$member->id}");
        $response->assertStatus(200);

        $this->assertDatabaseHas('utilisateurs_boutique', [
            'boutique_id' => $storeId,
            'utilisateur_id' => $member->id,
            'statut' => 'revoque',
        ]);

        // The revoked member no longer has any access at all.
        Sanctum::actingAs($member);
        $this->getJson("/api/boutiques/{$storeId}")->assertStatus(404);
    }
}

<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Models\User;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Tenancy\Models\StoreUser;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The critical suite required by docs/testing.md §2: a member of Store A
 * must never be able to reach Store B's data, whether by direct URL
 * access, by a client-supplied store_id, or by referencing a Store B
 * resource from a Store A request.
 */
class MultiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{userA: User, storeAId: int, userB: User, storeBId: int} */
    private function createTwoIsolatedStores(): array
    {
        $userA = User::factory()->create();
        Sanctum::actingAs($userA);
        $businessAId = $this->postJson('/api/entreprises', ['nom' => 'Business A'])->json('donnees.id');
        $storeAId = $this->postJson("/api/entreprises/{$businessAId}/boutiques", [
            'nom' => 'Store A',
            'domaine_activite_id' => BusinessDomain::factory()->create()->id,
        ])->json('donnees.id');

        $userB = User::factory()->create();
        Sanctum::actingAs($userB);
        $businessBId = $this->postJson('/api/entreprises', ['nom' => 'Business B'])->json('donnees.id');
        $storeBId = $this->postJson("/api/entreprises/{$businessBId}/boutiques", [
            'nom' => 'Store B',
            'domaine_activite_id' => BusinessDomain::factory()->create()->id,
        ])->json('donnees.id');

        return compact('userA', 'storeAId', 'userB', 'storeBId');
    }

    public function test_user_a_cannot_view_store_b(): void
    {
        ['userA' => $userA, 'storeBId' => $storeBId] = $this->createTwoIsolatedStores();

        Sanctum::actingAs($userA);
        $this->getJson("/api/boutiques/{$storeBId}")->assertStatus(404);
    }

    public function test_user_a_cannot_list_members_of_store_b(): void
    {
        ['userA' => $userA, 'storeBId' => $storeBId] = $this->createTwoIsolatedStores();

        Sanctum::actingAs($userA);
        $this->getJson("/api/boutiques/{$storeBId}/membres")->assertStatus(404);
    }

    public function test_user_a_cannot_add_a_member_to_store_b(): void
    {
        ['userA' => $userA, 'storeBId' => $storeBId] = $this->createTwoIsolatedStores();
        $victim = User::factory()->create();

        Sanctum::actingAs($userA);
        $this->postJson("/api/boutiques/{$storeBId}/membres", [
            'email' => $victim->email,
            'role' => 'proprietaire',
        ])->assertStatus(404);
    }

    public function test_user_a_only_sees_store_a_in_their_store_list(): void
    {
        ['userA' => $userA, 'storeAId' => $storeAId] = $this->createTwoIsolatedStores();

        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/boutiques');

        $response->assertStatus(200)->assertJsonCount(1, 'donnees')
            ->assertJsonPath('donnees.0.id', $storeAId);
    }

    /**
     * Section 23 "NE PAS FAIRE" / audit CRITIQUE: a client-supplied
     * store_id must never be trusted. There is no endpoint in this phase
     * that accepts a raw store_id from the request body, so the mechanism
     * is proven directly against the model layer here rather than faked
     * through an endpoint that doesn't take that input.
     */
    public function test_a_client_supplied_store_id_can_never_relocate_a_record(): void
    {
        ['storeAId' => $storeAId, 'storeBId' => $storeBId] = $this->createTwoIsolatedStores();

        app(TenantContextContract::class)->setStoreId($storeAId);

        $storeUser = StoreUser::create([
            'boutique_id' => $storeBId, // attacker-controlled value
            'utilisateur_id' => User::factory()->create()->id,
            'statut' => 'actif',
        ]);

        $this->assertSame($storeAId, $storeUser->boutique_id);
    }

    /**
     * The closest real analogue this phase has to "a Product/Sale ID from
     * Store B referenced in a Store A request": revoking a membership row
     * that only exists for Store B, from a request scoped to Store A.
     */
    public function test_cannot_revoke_a_membership_that_belongs_to_another_store(): void
    {
        ['userA' => $userA, 'storeAId' => $storeAId, 'userB' => $userB, 'storeBId' => $storeBId] = $this->createTwoIsolatedStores();

        // $userB is only a member of Store B, never of Store A.
        Sanctum::actingAs($userA);
        $response = $this->deleteJson("/api/boutiques/{$storeAId}/membres/{$userB->id}");

        $response->assertStatus(404);
    }
}

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
        $businessAId = $this->postJson('/api/businesses', ['name' => 'Business A'])->json('data.id');
        $storeAId = $this->postJson("/api/businesses/{$businessAId}/stores", [
            'name' => 'Store A',
            'business_domain_id' => BusinessDomain::factory()->create()->id,
        ])->json('data.id');

        $userB = User::factory()->create();
        Sanctum::actingAs($userB);
        $businessBId = $this->postJson('/api/businesses', ['name' => 'Business B'])->json('data.id');
        $storeBId = $this->postJson("/api/businesses/{$businessBId}/stores", [
            'name' => 'Store B',
            'business_domain_id' => BusinessDomain::factory()->create()->id,
        ])->json('data.id');

        return compact('userA', 'storeAId', 'userB', 'storeBId');
    }

    public function test_user_a_cannot_view_store_b(): void
    {
        ['userA' => $userA, 'storeBId' => $storeBId] = $this->createTwoIsolatedStores();

        Sanctum::actingAs($userA);
        $this->getJson("/api/stores/{$storeBId}")->assertStatus(404);
    }

    public function test_user_a_cannot_list_members_of_store_b(): void
    {
        ['userA' => $userA, 'storeBId' => $storeBId] = $this->createTwoIsolatedStores();

        Sanctum::actingAs($userA);
        $this->getJson("/api/stores/{$storeBId}/members")->assertStatus(404);
    }

    public function test_user_a_cannot_add_a_member_to_store_b(): void
    {
        ['userA' => $userA, 'storeBId' => $storeBId] = $this->createTwoIsolatedStores();
        $victim = User::factory()->create();

        Sanctum::actingAs($userA);
        $this->postJson("/api/stores/{$storeBId}/members", [
            'email' => $victim->email,
            'role' => 'owner',
        ])->assertStatus(404);
    }

    public function test_user_a_only_sees_store_a_in_their_store_list(): void
    {
        ['userA' => $userA, 'storeAId' => $storeAId] = $this->createTwoIsolatedStores();

        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/stores');

        $response->assertStatus(200)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $storeAId);
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
            'store_id' => $storeBId, // attacker-controlled value
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        $this->assertSame($storeAId, $storeUser->store_id);
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
        $response = $this->deleteJson("/api/stores/{$storeAId}/members/{$userB->id}");

        $response->assertStatus(404);
    }
}

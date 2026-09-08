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
        $businessId = $this->postJson('/api/businesses', ['name' => 'Boutique'])->json('data.id');
        $storeId = $this->postJson("/api/businesses/{$businessId}/stores", [
            'name' => 'Riz & Co',
            'business_domain_id' => BusinessDomain::factory()->create()->id,
        ])->json('data.id');

        return ['owner' => $owner, 'storeId' => $storeId];
    }

    public function test_owner_can_add_a_member_with_a_role(): void
    {
        ['owner' => $owner, 'storeId' => $storeId] = $this->createOwnerAndStore();
        $newMember = User::factory()->create();

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/stores/{$storeId}/members", [
            'email' => $newMember->email,
            'role' => 'cashier',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('store_users', [
            'store_id' => $storeId,
            'user_id' => $newMember->id,
            'status' => 'active',
        ]);
    }

    public function test_owner_can_list_members(): void
    {
        ['owner' => $owner, 'storeId' => $storeId] = $this->createOwnerAndStore();
        $newMember = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$storeId}/members", ['email' => $newMember->email, 'role' => 'cashier']);

        $response = $this->getJson("/api/stores/{$storeId}/members");

        $response->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_a_cashier_cannot_add_members(): void
    {
        ['owner' => $owner, 'storeId' => $storeId] = $this->createOwnerAndStore();
        $cashier = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$storeId}/members", ['email' => $cashier->email, 'role' => 'cashier']);

        $intruder = User::factory()->create();
        Sanctum::actingAs($cashier);
        $response = $this->postJson("/api/stores/{$storeId}/members", ['email' => $intruder->email, 'role' => 'employee']);

        $response->assertStatus(403);
    }

    public function test_a_cashier_can_still_view_members(): void
    {
        ['owner' => $owner, 'storeId' => $storeId] = $this->createOwnerAndStore();
        $cashier = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$storeId}/members", ['email' => $cashier->email, 'role' => 'cashier']);

        Sanctum::actingAs($cashier);
        $this->getJson("/api/stores/{$storeId}/members")->assertStatus(200);
    }

    public function test_owner_can_revoke_a_member(): void
    {
        ['owner' => $owner, 'storeId' => $storeId] = $this->createOwnerAndStore();
        $member = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$storeId}/members", ['email' => $member->email, 'role' => 'cashier']);

        $response = $this->deleteJson("/api/stores/{$storeId}/members/{$member->id}");
        $response->assertStatus(200);

        $this->assertDatabaseHas('store_users', [
            'store_id' => $storeId,
            'user_id' => $member->id,
            'status' => 'revoked',
        ]);

        // The revoked member no longer has any access at all.
        Sanctum::actingAs($member);
        $this->getJson("/api/stores/{$storeId}")->assertStatus(404);
    }
}

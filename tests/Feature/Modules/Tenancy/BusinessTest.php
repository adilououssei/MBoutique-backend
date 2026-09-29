<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_can_create_a_business(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/entreprises', ['nom' => 'Boutique Aïcha']);

        $response->assertStatus(201)->assertJsonPath('donnees.nom', 'Boutique Aïcha');

        $business = Business::first();
        $this->assertSame($user->id, $business->proprietaire_id);
        $this->assertDatabaseHas('utilisateurs_entreprise', [
            'entreprise_id' => $business->id,
            'utilisateur_id' => $user->id,
            'role' => 'proprietaire',
        ]);
    }

    public function test_a_guest_cannot_create_a_business(): void
    {
        $this->postJson('/api/entreprises', ['nom' => 'Boutique Aïcha'])->assertStatus(401);
    }

    public function test_a_business_member_can_view_it(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $businessId = $this->postJson('/api/entreprises', ['nom' => 'Boutique Aïcha'])->json('donnees.id');

        $this->getJson("/api/entreprises/{$businessId}")->assertStatus(200);
    }

    public function test_a_non_member_cannot_view_a_business(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $businessId = $this->postJson('/api/entreprises', ['nom' => 'Boutique Aïcha'])->json('donnees.id');

        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);

        $this->getJson("/api/entreprises/{$businessId}")->assertStatus(403);
    }

    public function test_a_user_can_list_the_businesses_they_belong_to(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/entreprises', ['nom' => 'Boutique 1']);
        $this->postJson('/api/entreprises', ['nom' => 'Boutique 2']);

        $response = $this->getJson('/api/entreprises');

        $response->assertStatus(200)->assertJsonCount(2, 'donnees');
    }
}

<?php

namespace Tests\Feature\Modules\CashRegister;

use App\Modules\CashRegister\Models\CashRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class CashRegisterTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    public function test_owner_can_create_a_cash_register(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/boutiques/{$store->id}/caisses", [
            'nom' => 'Caisse principale', 'code' => 'CAISSE-01',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.nom', 'Caisse principale')
            ->assertJsonPath('donnees.code', 'CAISSE-01')
            ->assertJsonPath('donnees.est_ouverte', false);
        $this->assertDatabaseHas('caisses', ['boutique_id' => $store->id, 'code' => 'CAISSE-01']);
    }

    public function test_a_cash_register_can_be_created_without_a_code(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/caisses", ['nom' => 'Caisse 2'])->assertStatus(201);
    }

    public function test_the_code_must_be_unique_per_store_not_globally(): void
    {
        ['proprietaire' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['caisse']);
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/boutiques/{$storeA->id}/caisses", ['nom' => 'A', 'code' => 'C1'])->assertStatus(201);
        $this->postJson("/api/boutiques/{$storeA->id}/caisses", ['nom' => 'B', 'code' => 'C1'])
            ->assertStatus(422)->assertJsonValidationErrors('code', 'erreurs');

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['caisse']);
        Sanctum::actingAs($ownerB);
        $this->postJson("/api/boutiques/{$storeB->id}/caisses", ['nom' => 'C', 'code' => 'C1'])->assertStatus(201);
    }

    public function test_two_registers_without_a_code_are_both_valid(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/caisses", ['nom' => 'A'])->assertStatus(201);
        $this->postJson("/api/boutiques/{$store->id}/caisses", ['nom' => 'B'])->assertStatus(201);

        $this->assertDatabaseCount('caisses', 2);
    }

    public function test_owner_can_list_read_and_update_a_cash_register(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/caisses")->assertStatus(200)->assertJsonCount(1, 'donnees');
        $this->getJson("/api/boutiques/{$store->id}/caisses/{$register->id}")->assertStatus(200);

        $this->putJson("/api/boutiques/{$store->id}/caisses/{$register->id}", ['nom' => 'Renamed'])
            ->assertStatus(200)->assertJsonPath('donnees.nom', 'Renamed');
    }

    public function test_a_register_is_deactivated_not_deleted(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->putJson("/api/boutiques/{$store->id}/caisses/{$register->id}", ['actif' => false])
            ->assertStatus(200)->assertJsonPath('donnees.actif', false);

        $this->assertDatabaseHas('caisses', ['id' => $register->id]);
    }

    // --- Isolation ---

    public function test_a_member_of_another_store_cannot_reach_this_registers_store(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['caisse']);
        $registerA = CashRegister::factory()->for($storeA)->create();

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['caisse']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/boutiques/{$storeB->id}/caisses/{$registerA->id}")->assertStatus(404);
        $this->putJson("/api/boutiques/{$storeB->id}/caisses/{$registerA->id}", ['nom' => 'x'])->assertStatus(404);
    }
}

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
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/stores/{$store->id}/cash-registers", [
            'name' => 'Caisse principale', 'code' => 'CAISSE-01',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Caisse principale')
            ->assertJsonPath('data.code', 'CAISSE-01')
            ->assertJsonPath('data.is_open', false);
        $this->assertDatabaseHas('cash_registers', ['store_id' => $store->id, 'code' => 'CAISSE-01']);
    }

    public function test_a_cash_register_can_be_created_without_a_code(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/cash-registers", ['name' => 'Caisse 2'])->assertStatus(201);
    }

    public function test_the_code_must_be_unique_per_store_not_globally(): void
    {
        ['owner' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['cash_register']);
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/stores/{$storeA->id}/cash-registers", ['name' => 'A', 'code' => 'C1'])->assertStatus(201);
        $this->postJson("/api/stores/{$storeA->id}/cash-registers", ['name' => 'B', 'code' => 'C1'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['cash_register']);
        Sanctum::actingAs($ownerB);
        $this->postJson("/api/stores/{$storeB->id}/cash-registers", ['name' => 'C', 'code' => 'C1'])->assertStatus(201);
    }

    public function test_two_registers_without_a_code_are_both_valid(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/cash-registers", ['name' => 'A'])->assertStatus(201);
        $this->postJson("/api/stores/{$store->id}/cash-registers", ['name' => 'B'])->assertStatus(201);

        $this->assertDatabaseCount('cash_registers', 2);
    }

    public function test_owner_can_list_read_and_update_a_cash_register(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/stores/{$store->id}/cash-registers")->assertStatus(200)->assertJsonCount(1, 'data');
        $this->getJson("/api/stores/{$store->id}/cash-registers/{$register->id}")->assertStatus(200);

        $this->putJson("/api/stores/{$store->id}/cash-registers/{$register->id}", ['name' => 'Renamed'])
            ->assertStatus(200)->assertJsonPath('data.name', 'Renamed');
    }

    public function test_a_register_is_deactivated_not_deleted(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->putJson("/api/stores/{$store->id}/cash-registers/{$register->id}", ['is_active' => false])
            ->assertStatus(200)->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('cash_registers', ['id' => $register->id]);
    }

    // --- Isolation ---

    public function test_a_member_of_another_store_cannot_reach_this_registers_store(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['cash_register']);
        $registerA = CashRegister::factory()->for($storeA)->create();

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['cash_register']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/stores/{$storeB->id}/cash-registers/{$registerA->id}")->assertStatus(404);
        $this->putJson("/api/stores/{$storeB->id}/cash-registers/{$registerA->id}", ['name' => 'x'])->assertStatus(404);
    }
}

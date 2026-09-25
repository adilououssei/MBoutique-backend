<?php

namespace Tests\Feature\Modules\CashRegister;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class CashRegisterSessionTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    public function test_owner_can_open_a_session_with_an_opening_amount(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", [
            'opening_amount' => 50000,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.opening_amount', '50000.00')
            ->assertJsonPath('data.current_balance', '50000.00');

        $this->assertDatabaseHas('cash_registers', ['id' => $register->id, 'open_session_id' => $response->json('data.id')]);
    }

    public function test_opening_a_session_records_an_opening_movement(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 50000])
            ->json('data.id');

        $movements = $this->getJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/movements")
            ->json('data');

        $this->assertCount(1, $movements);
        $this->assertSame('opening', $movements[0]['type']);
        $this->assertSame('50000.00', $movements[0]['amount']);
        $this->assertSame('0.00', $movements[0]['balance_before']);
        $this->assertSame('50000.00', $movements[0]['balance_after']);
    }

    public function test_a_register_cannot_have_two_open_sessions_at_once(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 50000])
            ->assertStatus(201);

        $response = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 10000]);

        $response->assertStatus(422)->assertJsonPath('code', 'CASH_REGISTER_ALREADY_OPEN');
        $this->assertDatabaseCount('cash_register_sessions', 1);
    }

    public function test_a_deactivated_register_cannot_be_opened(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->inactive()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 50000])
            ->assertStatus(422)->assertJsonPath('code', 'CASH_REGISTER_INACTIVE');
    }

    public function test_current_session_returns_null_when_nothing_is_open(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/stores/{$store->id}/cash-registers/{$register->id}/current-session")
            ->assertStatus(200)->assertJsonPath('data', null);
    }

    public function test_current_session_returns_the_open_session(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 50000])
            ->json('data.id');

        $this->getJson("/api/stores/{$store->id}/cash-registers/{$register->id}/current-session")
            ->assertStatus(200)->assertJsonPath('data.id', $sessionId);
    }

    // --- Closing ---

    public function test_closing_computes_the_difference_as_a_shortfall(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 50000])
            ->json('data.id');
        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/cash-in", ['amount' => 20000]);
        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/cash-out", ['amount' => 5000]);
        // expected = 65000

        $response = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/close", [
            'actual_closing_amount' => 64000,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.expected_closing_amount', '65000.00')
            ->assertJsonPath('data.actual_closing_amount', '64000.00')
            ->assertJsonPath('data.difference', '-1000.00');
    }

    public function test_closing_computes_the_difference_as_a_surplus(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 50000])
            ->json('data.id');
        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/cash-in", ['amount' => 15000]);
        // expected = 65000

        $response = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/close", [
            'actual_closing_amount' => 66000,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.difference', '1000.00');
    }

    public function test_the_register_can_be_reopened_after_closing(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 50000])
            ->json('data.id');
        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/close", ['actual_closing_amount' => 50000])
            ->assertStatus(200);

        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 10000])
            ->assertStatus(201);

        $this->assertDatabaseCount('cash_register_sessions', 2);
    }

    public function test_a_closed_session_cannot_receive_new_movements(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 50000])
            ->json('data.id');
        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/close", ['actual_closing_amount' => 50000]);

        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/cash-in", ['amount' => 5000])
            ->assertStatus(422)->assertJsonPath('code', 'CASH_REGISTER_SESSION_CLOSED');
    }

    public function test_a_session_cannot_be_closed_twice(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 50000])
            ->json('data.id');
        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/close", ['actual_closing_amount' => 50000])
            ->assertStatus(200);

        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/close", ['actual_closing_amount' => 50000])
            ->assertStatus(422)->assertJsonPath('code', 'CASH_REGISTER_SESSION_CLOSED');
    }

    // --- History / pagination ---

    public function test_session_history_is_paginated(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        for ($i = 0; $i < 3; $i++) {
            $id = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 1000])->json('data.id');
            $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$id}/close", ['actual_closing_amount' => 1000]);
        }

        $response = $this->getJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions?per_page=2");

        $response->assertStatus(200)->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
    }

    // --- Isolation ---

    public function test_a_member_of_another_store_cannot_open_a_session_on_this_register(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['cash_register']);
        $registerA = CashRegister::factory()->for($storeA)->create();

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['cash_register']);
        Sanctum::actingAs($ownerB);

        $this->postJson("/api/stores/{$storeB->id}/cash-registers/{$registerA->id}/sessions", ['opening_amount' => 1000])
            ->assertStatus(404);
    }

    // --- Permissions ---

    public function test_an_employee_can_view_but_not_open_a_session(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        $employee = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/members", ['email' => $employee->email, 'role' => 'employee'])->assertStatus(201);

        Sanctum::actingAs($employee);

        $this->getJson("/api/stores/{$store->id}/cash-registers/{$register->id}/current-session")->assertStatus(200);
        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 1000])
            ->assertStatus(403);
    }

    public function test_a_cashier_can_open_and_close_a_session(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        $cashier = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/members", ['email' => $cashier->email, 'role' => 'cashier'])->assertStatus(201);

        Sanctum::actingAs($cashier);

        $sessionId = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 1000])
            ->assertStatus(201)->json('data.id');
        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/close", ['actual_closing_amount' => 1000])
            ->assertStatus(200);
    }

    public function test_a_cashier_cannot_create_a_new_cash_register(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $cashier = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/members", ['email' => $cashier->email, 'role' => 'cashier'])->assertStatus(201);

        Sanctum::actingAs($cashier);

        $this->postJson("/api/stores/{$store->id}/cash-registers", ['name' => 'Nouvelle caisse'])->assertStatus(403);
    }
}

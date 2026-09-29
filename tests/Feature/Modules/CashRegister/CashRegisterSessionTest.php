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
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", [
            'montant_ouverture' => 50000,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.statut', 'ouverte')
            ->assertJsonPath('donnees.montant_ouverture', '50000.00')
            ->assertJsonPath('donnees.solde_courant', '50000.00');

        $this->assertDatabaseHas('caisses', ['id' => $register->id, 'session_ouverte_id' => $response->json('donnees.id')]);
    }

    public function test_opening_a_session_records_an_opening_movement(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 50000])
            ->json('donnees.id');

        $movements = $this->getJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/mouvements")
            ->json('donnees');

        $this->assertCount(1, $movements);
        $this->assertSame('ouverture', $movements[0]['type']);
        $this->assertSame('50000.00', $movements[0]['montant']);
        $this->assertSame('0.00', $movements[0]['solde_avant']);
        $this->assertSame('50000.00', $movements[0]['solde_apres']);
    }

    public function test_a_register_cannot_have_two_open_sessions_at_once(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 50000])
            ->assertStatus(201);

        $response = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 10000]);

        $response->assertStatus(422)->assertJsonPath('code', 'CAISSE_DEJA_OUVERTE');
        $this->assertDatabaseCount('sessions_caisse', 1);
    }

    public function test_a_deactivated_register_cannot_be_opened(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->inactive()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 50000])
            ->assertStatus(422)->assertJsonPath('code', 'CAISSE_INACTIVE');
    }

    public function test_current_session_returns_null_when_nothing_is_open(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/caisses/{$register->id}/session-courante")
            ->assertStatus(200)->assertJsonPath('donnees', null);
    }

    public function test_current_session_returns_the_open_session(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 50000])
            ->json('donnees.id');

        $this->getJson("/api/boutiques/{$store->id}/caisses/{$register->id}/session-courante")
            ->assertStatus(200)->assertJsonPath('donnees.id', $sessionId);
    }

    // --- Closing ---

    public function test_closing_computes_the_difference_as_a_shortfall(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 50000])
            ->json('donnees.id');
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/entree", ['montant' => 20000]);
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/sortie", ['montant' => 5000]);
        // expected = 65000

        $response = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/fermer", [
            'montant_fermeture_reel' => 64000,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('donnees.statut', 'fermee')
            ->assertJsonPath('donnees.montant_fermeture_attendu', '65000.00')
            ->assertJsonPath('donnees.montant_fermeture_reel', '64000.00')
            ->assertJsonPath('donnees.ecart', '-1000.00');
    }

    public function test_closing_computes_the_difference_as_a_surplus(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 50000])
            ->json('donnees.id');
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/entree", ['montant' => 15000]);
        // expected = 65000

        $response = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/fermer", [
            'montant_fermeture_reel' => 66000,
        ]);

        $response->assertStatus(200)->assertJsonPath('donnees.ecart', '1000.00');
    }

    public function test_the_register_can_be_reopened_after_closing(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 50000])
            ->json('donnees.id');
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/fermer", ['montant_fermeture_reel' => 50000])
            ->assertStatus(200);

        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 10000])
            ->assertStatus(201);

        $this->assertDatabaseCount('sessions_caisse', 2);
    }

    public function test_a_closed_session_cannot_receive_new_movements(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 50000])
            ->json('donnees.id');
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/fermer", ['montant_fermeture_reel' => 50000]);

        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/entree", ['montant' => 5000])
            ->assertStatus(422)->assertJsonPath('code', 'SESSION_CAISSE_FERMEE');
    }

    public function test_a_session_cannot_be_closed_twice(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 50000])
            ->json('donnees.id');
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/fermer", ['montant_fermeture_reel' => 50000])
            ->assertStatus(200);

        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/fermer", ['montant_fermeture_reel' => 50000])
            ->assertStatus(422)->assertJsonPath('code', 'SESSION_CAISSE_FERMEE');
    }

    // --- History / pagination ---

    public function test_session_history_is_paginated(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        for ($i = 0; $i < 3; $i++) {
            $id = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 1000])->json('donnees.id');
            $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$id}/fermer", ['montant_fermeture_reel' => 1000]);
        }

        $response = $this->getJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions?par_page=2");

        $response->assertStatus(200)->assertJsonCount(2, 'donnees')->assertJsonPath('meta.total', 3);
    }

    // --- Isolation ---

    public function test_a_member_of_another_store_cannot_open_a_session_on_this_register(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['caisse']);
        $registerA = CashRegister::factory()->for($storeA)->create();

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['caisse']);
        Sanctum::actingAs($ownerB);

        $this->postJson("/api/boutiques/{$storeB->id}/caisses/{$registerA->id}/sessions", ['montant_ouverture' => 1000])
            ->assertStatus(404);
    }

    // --- Permissions ---

    public function test_an_employee_can_view_but_not_open_a_session(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        $employee = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $employee->email, 'role' => 'employe'])->assertStatus(201);

        Sanctum::actingAs($employee);

        $this->getJson("/api/boutiques/{$store->id}/caisses/{$register->id}/session-courante")->assertStatus(200);
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 1000])
            ->assertStatus(403);
    }

    public function test_a_cashier_can_open_and_close_a_session(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        $cashier = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);

        Sanctum::actingAs($cashier);

        $sessionId = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 1000])
            ->assertStatus(201)->json('donnees.id');
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/fermer", ['montant_fermeture_reel' => 1000])
            ->assertStatus(200);
    }

    public function test_a_cashier_cannot_create_a_new_cash_register(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $cashier = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);

        Sanctum::actingAs($cashier);

        $this->postJson("/api/boutiques/{$store->id}/caisses", ['nom' => 'Nouvelle caisse'])->assertStatus(403);
    }
}

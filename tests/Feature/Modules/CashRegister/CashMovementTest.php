<?php

namespace Tests\Feature\Modules\CashRegister;

use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class CashMovementTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private function openSession(int $storeId, int $registerId, float $opening = 50000): int
    {
        return $this->postJson("/api/boutiques/{$storeId}/caisses/{$registerId}/sessions", ['montant_ouverture' => $opening])
            ->json('donnees.id');
    }

    public function test_cash_in_increases_the_balance(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id);

        $response = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/entree", [
            'montant' => 20000, 'motif' => 'Fond supplémentaire',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.type', 'entree')
            ->assertJsonPath('donnees.montant', '20000.00')
            ->assertJsonPath('donnees.solde_avant', '50000.00')
            ->assertJsonPath('donnees.solde_apres', '70000.00');
    }

    public function test_cash_out_decreases_the_balance(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id, 70000);

        $response = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/sortie", [
            'montant' => 5000, 'motif' => 'Achat de fournitures',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.montant', '-5000.00')
            ->assertJsonPath('donnees.solde_avant', '70000.00')
            ->assertJsonPath('donnees.solde_apres', '65000.00');
    }

    public function test_cash_out_rejects_an_amount_exceeding_the_balance(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id, 5000);

        $response = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/sortie", ['montant' => 10000]);

        $response->assertStatus(422)->assertJsonPath('code', 'SOLDE_CAISSE_INSUFFISANT');
        $this->assertSame(1, CashMovement::where('session_caisse_id', $sessionId)->count()); // only the opening
    }

    public function test_an_adjustment_can_be_negative(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id, 100000);

        $response = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/ajustement", [
            'montant' => -500, 'motif' => 'Erreur de comptage',
        ]);

        $response->assertStatus(201)->assertJsonPath('donnees.solde_apres', '99500.00');
    }

    public function test_an_adjustment_requires_a_reason(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id);

        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/ajustement", ['montant' => -500])
            ->assertStatus(422)->assertJsonValidationErrors('motif', 'erreurs');
    }

    public function test_an_adjustment_of_zero_is_rejected(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id);

        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/ajustement", ['montant' => 0, 'motif' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('montant', 'erreurs');
    }

    // --- Ledger ---

    public function test_the_full_scenario_from_the_brief_produces_the_expected_balance(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id, 50000);

        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/entree", ['montant' => 20000])->assertStatus(201);
        $response = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/sortie", ['montant' => 5000]);

        $response->assertStatus(201)->assertJsonPath('donnees.solde_apres', '65000.00'); // 50000 + 20000 - 5000
    }

    // --- Immutability ---

    public function test_a_cash_movement_cannot_be_updated_or_deleted_through_the_api(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id);
        $movementId = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/entree", ['montant' => 1000])
            ->json('donnees.id');

        // No such routes exist — a correction is a new adjustment, never an edit.
        $this->putJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/mouvements/{$movementId}", ['montant' => 999])
            ->assertStatus(404);
        $this->deleteJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/mouvements/{$movementId}")
            ->assertStatus(404);
    }

    /**
     * A genuine multi-connection race isn't reproducible against SQLite
     * in-memory — see docs/cash-register.md §"Concurrence" for the
     * acknowledged limitation (same as Inventory's, docs/inventory.md
     * §7). This proves the sequential business outcome the locking must
     * guarantee: two cash-outs that individually look valid but
     * together overdraw the drawer must not both succeed.
     */
    public function test_two_sequential_cash_outs_that_together_exceed_the_balance_cannot_both_succeed(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id, 5000);

        $first = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/sortie", ['montant' => 4000]);
        $second = $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/sortie", ['montant' => 3000]);

        $first->assertStatus(201);
        $second->assertStatus(422)->assertJsonPath('code', 'SOLDE_CAISSE_INSUFFISANT');
    }

    // --- Isolation ---

    public function test_a_member_of_another_store_cannot_record_a_movement_on_this_session(): void
    {
        ['proprietaire' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['caisse']);
        $registerA = CashRegister::factory()->for($storeA)->create();
        Sanctum::actingAs($ownerA);
        $sessionId = $this->openSession($storeA->id, $registerA->id);

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['caisse']);
        Sanctum::actingAs($ownerB);

        $this->postJson("/api/boutiques/{$storeB->id}/caisses/{$registerA->id}/sessions/{$sessionId}/entree", ['montant' => 1000])
            ->assertStatus(404);
    }

    // --- FeatureGate ---

    public function test_cash_register_is_blocked_when_the_domain_never_enabled_the_feature(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']); // cash_register NOT enabled
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/caisses")
            ->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');
    }
}

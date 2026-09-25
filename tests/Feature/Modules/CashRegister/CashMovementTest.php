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
        return $this->postJson("/api/stores/{$storeId}/cash-registers/{$registerId}/sessions", ['opening_amount' => $opening])
            ->json('data.id');
    }

    public function test_cash_in_increases_the_balance(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id);

        $response = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/cash-in", [
            'amount' => 20000, 'reason' => 'Fond supplémentaire',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'cash_in')
            ->assertJsonPath('data.amount', '20000.00')
            ->assertJsonPath('data.balance_before', '50000.00')
            ->assertJsonPath('data.balance_after', '70000.00');
    }

    public function test_cash_out_decreases_the_balance(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id, 70000);

        $response = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/cash-out", [
            'amount' => 5000, 'reason' => 'Achat de fournitures',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.amount', '-5000.00')
            ->assertJsonPath('data.balance_before', '70000.00')
            ->assertJsonPath('data.balance_after', '65000.00');
    }

    public function test_cash_out_rejects_an_amount_exceeding_the_balance(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id, 5000);

        $response = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/cash-out", ['amount' => 10000]);

        $response->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_CASH');
        $this->assertSame(1, CashMovement::where('cash_register_session_id', $sessionId)->count()); // only the opening
    }

    public function test_an_adjustment_can_be_negative(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id, 100000);

        $response = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/adjust", [
            'amount' => -500, 'reason' => 'Erreur de comptage',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.balance_after', '99500.00');
    }

    public function test_an_adjustment_requires_a_reason(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id);

        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/adjust", ['amount' => -500])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_an_adjustment_of_zero_is_rejected(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id);

        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/adjust", ['amount' => 0, 'reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    // --- Ledger ---

    public function test_the_full_scenario_from_the_brief_produces_the_expected_balance(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id, 50000);

        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/cash-in", ['amount' => 20000])->assertStatus(201);
        $response = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/cash-out", ['amount' => 5000]);

        $response->assertStatus(201)->assertJsonPath('data.balance_after', '65000.00'); // 50000 + 20000 - 5000
    }

    // --- Immutability ---

    public function test_a_cash_movement_cannot_be_updated_or_deleted_through_the_api(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id);
        $movementId = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/cash-in", ['amount' => 1000])
            ->json('data.id');

        // No such routes exist — a correction is a new adjustment, never an edit.
        $this->putJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/movements/{$movementId}", ['amount' => 999])
            ->assertStatus(404);
        $this->deleteJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/movements/{$movementId}")
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
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['cash_register']);
        $register = CashRegister::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $sessionId = $this->openSession($store->id, $register->id, 5000);

        $first = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/cash-out", ['amount' => 4000]);
        $second = $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/cash-out", ['amount' => 3000]);

        $first->assertStatus(201);
        $second->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_CASH');
    }

    // --- Isolation ---

    public function test_a_member_of_another_store_cannot_record_a_movement_on_this_session(): void
    {
        ['owner' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['cash_register']);
        $registerA = CashRegister::factory()->for($storeA)->create();
        Sanctum::actingAs($ownerA);
        $sessionId = $this->openSession($storeA->id, $registerA->id);

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['cash_register']);
        Sanctum::actingAs($ownerB);

        $this->postJson("/api/stores/{$storeB->id}/cash-registers/{$registerA->id}/sessions/{$sessionId}/cash-in", ['amount' => 1000])
            ->assertStatus(404);
    }

    // --- FeatureGate ---

    public function test_cash_register_is_blocked_when_the_domain_never_enabled_the_feature(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']); // cash_register NOT enabled
        Sanctum::actingAs($owner);

        $this->getJson("/api/stores/{$store->id}/cash-registers")
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }
}

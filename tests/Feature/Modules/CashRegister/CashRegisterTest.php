<?php

namespace Tests\Feature\Modules\CashRegister;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
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

    public function test_a_person_has_a_single_active_register_per_store(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        Sanctum::actingAs($owner);
        $first = $this->postJson("/api/boutiques/{$store->id}/caisses", ['nom' => 'A'])->assertStatus(201)->json('donnees.id');
        $this->postJson("/api/boutiques/{$store->id}/caisses", ['nom' => 'B'])
            ->assertStatus(422)->assertJsonPath('code', 'CAISSE_DEJA_ATTRIBUEE');

        // Désactivée, elle libère la place ; la réactiver ensuite est refusé.
        $this->putJson("/api/boutiques/{$store->id}/caisses/{$first}", ['actif' => false])->assertOk();
        $this->postJson("/api/boutiques/{$store->id}/caisses", ['nom' => 'B'])->assertStatus(201);
        $this->putJson("/api/boutiques/{$store->id}/caisses/{$first}", ['actif' => true])
            ->assertStatus(422)->assertJsonPath('code', 'CAISSE_DEJA_ATTRIBUEE');

        $this->assertDatabaseCount('caisses', 2);
    }

    public function test_only_the_creator_can_update_their_register(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $manager = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $manager->email, 'role' => 'gerant'])->assertStatus(201);
        $mine = $this->postJson("/api/boutiques/{$store->id}/caisses", ['nom' => 'Caisse du patron'])->json('donnees.id');

        Sanctum::actingAs($manager);
        $this->putJson("/api/boutiques/{$store->id}/caisses/{$mine}", ['nom' => 'Piratée'])->assertStatus(403);
        $this->getJson("/api/boutiques/{$store->id}/caisses/{$mine}")
            ->assertOk()->assertJsonPath('donnees.est_a_moi', false)->assertJsonPath('donnees.modifiable', false);

        Sanctum::actingAs($owner);
        $this->putJson("/api/boutiques/{$store->id}/caisses/{$mine}", ['nom' => 'Caisse 1'])->assertOk()->assertJsonPath('donnees.nom', 'Caisse 1');
    }

    public function test_the_journal_totals_sales_per_day(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse']);
        $this->travelTo(CarbonImmutable::parse('2026-10-04 15:00:00', 'UTC'));
        $register = CashRegister::factory()->for($store)->create();
        $session = CashRegisterSession::factory()->for($store)->create(['caisse_id' => $register->id]);
        $sale = fn (string $at, int $total, array $extra = []) => Sale::factory()->for($store)->create([
            'caisse_id' => $register->id, 'session_caisse_id' => $session->id, 'sous_total' => $total, 'montant_remise' => 0,
            'montant_total' => $total, 'vendue_le' => CarbonImmutable::parse($at, 'UTC'), ...$extra,
        ]);
        $sale('2026-10-04 09:00:00', 1000, ['montant_especes' => 1000]);
        $sale('2026-10-04 10:00:00', 3000, ['montant_acompte' => 500, 'montant_especes' => 500]);
        $sale('2026-10-03 18:00:00', 2000, ['montant_especes' => 0]);
        $sale('2026-10-03 19:00:00', 9999, ['statut' => SaleStatus::Cancelled]);
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/caisses/{$register->id}/journal")
            ->assertOk()
            ->assertJsonPath('donnees.aujourdhui.date', '2026-10-04')
            ->assertJsonPath('donnees.aujourdhui.nombre_ventes', 2)
            ->assertJsonPath('donnees.aujourdhui.chiffre_affaires', '4000.00')
            ->assertJsonPath('donnees.aujourdhui.especes', '1500.00')
            ->assertJsonPath('donnees.aujourdhui.credit', '2500.00')
            ->assertJsonCount(1, 'donnees.jours')
            ->assertJsonPath('donnees.jours.0.date', '2026-10-03')
            ->assertJsonPath('donnees.jours.0.chiffre_affaires', '2000.00')
            ->assertJsonPath('donnees.jours.0.especes', '0.00');
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

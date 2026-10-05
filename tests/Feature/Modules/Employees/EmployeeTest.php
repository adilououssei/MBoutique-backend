<?php

namespace Tests\Feature\Modules\Employees;

use App\Models\User;
use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Personnel et paiements (salaire, avance, prime) — docs/modules.md §Employees. */
class EmployeeTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{owner: User, store: Store, register: CashRegister} */
    private function setUpStore(float $opening = 100000): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse', 'ventes', 'employes']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => $opening])->assertStatus(201);

        return ['owner' => $owner, 'store' => $store, 'register' => $register];
    }

    public function test_owner_can_create_list_update_and_delete_an_employee(): void
    {
        ['store' => $store] = $this->setUpStore();
        $base = "/api/boutiques/{$store->id}/employes";

        $id = $this->postJson($base, ['nom' => 'Awa Traoré', 'poste' => 'Vendeuse', 'salaire' => 75000, 'periodicite_salaire' => 'mensuel', 'date_embauche' => '2026-01-15'])
            ->assertStatus(201)
            ->assertJsonPath('donnees.salaire', '75000.00')
            ->assertJsonPath('donnees.periodicite_salaire', 'mensuel')
            ->assertJsonPath('donnees.date_embauche', '2026-01-15')
            ->json('donnees.id');

        $this->getJson($base)->assertStatus(200)->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.paye_ce_mois', '0.00');
        $this->getJson("{$base}?recherche=vendeuse")->assertJsonCount(1, 'donnees');
        $this->putJson("{$base}/{$id}", ['poste' => 'Caissière', 'actif' => false])->assertStatus(200)->assertJsonPath('donnees.poste', 'Caissière');
        $this->deleteJson("{$base}/{$id}")->assertStatus(200);
        $this->assertSoftDeleted('employes', ['id' => $id]);
    }

    public function test_a_salary_needs_its_frequency(): void
    {
        ['store' => $store] = $this->setUpStore();

        $this->postJson("/api/boutiques/{$store->id}/employes", ['nom' => 'Ali', 'salaire' => 50000])
            ->assertStatus(422)->assertJsonValidationErrors('periodicite_salaire', 'erreurs');
    }

    public function test_an_employee_can_be_linked_only_to_a_member_of_the_store_once(): void
    {
        ['store' => $store] = $this->setUpStore();
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $member->email, 'role' => 'caissier'])->assertStatus(201);
        $base = "/api/boutiques/{$store->id}/employes";

        $this->postJson($base, ['nom' => 'Membre', 'utilisateur_id' => $member->id])
            ->assertStatus(201)->assertJsonPath('donnees.compte.email', $member->email);
        $this->postJson($base, ['nom' => 'Doublon', 'utilisateur_id' => $member->id])
            ->assertStatus(422)->assertJsonValidationErrors('utilisateur_id', 'erreurs');
        $this->postJson($base, ['nom' => 'Inconnu', 'utilisateur_id' => $stranger->id])
            ->assertStatus(422)->assertJsonValidationErrors('utilisateur_id', 'erreurs');
    }

    public function test_paying_from_the_register_records_a_referenced_cash_out(): void
    {
        ['store' => $store, 'register' => $register] = $this->setUpStore();
        $employee = Employee::factory()->for($store)->create(['nom' => 'Awa']);

        $payment = $this->postJson("/api/boutiques/{$store->id}/employes/{$employee->id}/paiements", [
            'type' => 'salaire', 'montant' => 75000, 'mode' => 'caisse', 'caisse_id' => $register->id, 'periode' => 'Octobre 2026',
        ])->assertStatus(201)->assertJsonPath('donnees.type', 'salaire')->assertJsonPath('donnees.periode', 'Octobre 2026')->json('donnees');

        $movement = CashMovement::where('type', 'sortie')->firstOrFail();
        $this->assertSame('-75000.00', (string) $movement->montant);
        $this->assertSame('25000.00', (string) $movement->solde_apres);
        $this->assertSame('paiement_employe', $movement->reference_type);
        $this->assertSame($payment['id'], $movement->reference_id);
        $this->assertStringContainsString('Salaire — Awa', $movement->motif);
    }

    public function test_an_external_payment_does_not_touch_the_register_and_month_total_adds_up(): void
    {
        ['store' => $store, 'register' => $register] = $this->setUpStore();
        $employee = Employee::factory()->for($store)->create();
        $url = "/api/boutiques/{$store->id}/employes/{$employee->id}/paiements";

        $this->postJson($url, ['type' => 'avance', 'montant' => 20000, 'mode' => 'externe', 'note' => 'Orange Money'])->assertStatus(201);
        $this->postJson($url, ['type' => 'prime', 'montant' => 5000, 'mode' => 'caisse', 'caisse_id' => $register->id])->assertStatus(201);

        $this->assertSame(1, CashMovement::where('type', 'sortie')->count());
        $this->getJson("/api/boutiques/{$store->id}/employes/{$employee->id}")->assertJsonPath('donnees.paye_ce_mois', '25000.00');
        $this->getJson($url)->assertJsonCount(2, 'donnees')->assertJsonPath('donnees.0.type', 'prime');
    }

    public function test_a_payment_beyond_the_drawer_balance_or_from_a_closed_register_is_rolled_back(): void
    {
        ['store' => $store, 'register' => $register] = $this->setUpStore(opening: 10000);
        $employee = Employee::factory()->for($store)->create();
        $url = "/api/boutiques/{$store->id}/employes/{$employee->id}/paiements";

        $this->postJson($url, ['type' => 'salaire', 'montant' => 50000, 'mode' => 'caisse', 'caisse_id' => $register->id])
            ->assertStatus(422)->assertJsonPath('code', 'SOLDE_CAISSE_INSUFFISANT');

        $closed = CashRegister::factory()->for($store)->create();
        $this->postJson($url, ['type' => 'salaire', 'montant' => 100, 'mode' => 'caisse', 'caisse_id' => $closed->id])
            ->assertStatus(422)->assertJsonPath('code', 'AUCUNE_SESSION_CAISSE_OUVERTE');

        $this->assertDatabaseCount('paiements_employe', 0);
    }

    public function test_a_register_is_required_when_paying_from_the_register(): void
    {
        ['store' => $store] = $this->setUpStore();
        $employee = Employee::factory()->for($store)->create();

        $this->postJson("/api/boutiques/{$store->id}/employes/{$employee->id}/paiements", ['type' => 'salaire', 'montant' => 100, 'mode' => 'caisse'])
            ->assertStatus(422)->assertJsonValidationErrors('caisse_id', 'erreurs');
    }

    public function test_salaries_are_hidden_from_cashiers_but_managed_by_managers(): void
    {
        ['store' => $store] = $this->setUpStore();
        $employee = Employee::factory()->for($store)->create();
        $cashier = User::factory()->create();
        $manager = User::factory()->create();
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $manager->email, 'role' => 'gerant'])->assertStatus(201);

        Sanctum::actingAs($cashier);
        $this->getJson("/api/boutiques/{$store->id}/employes")->assertStatus(403);

        Sanctum::actingAs($manager);
        $this->getJson("/api/boutiques/{$store->id}/employes")->assertStatus(200);
        $this->postJson("/api/boutiques/{$store->id}/employes/{$employee->id}/paiements", ['type' => 'avance', 'montant' => 1000, 'mode' => 'externe'])->assertStatus(201);
    }

    public function test_feature_gate_and_store_isolation(): void
    {
        ['proprietaire' => $owner, 'store' => $noFeature] = $this->createStoreWithFeatures(['ventes']);
        $this->getJson("/api/boutiques/{$noFeature->id}/employes")->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');

        ['owner' => $storeOwner, 'store' => $store] = $this->setUpStore();
        ['store' => $other] = $this->createStoreWithFeatures(['employes']);
        $foreign = Employee::factory()->for($other)->create();
        Sanctum::actingAs($storeOwner);

        $this->getJson("/api/boutiques/{$store->id}/employes/{$foreign->id}")->assertStatus(404);
    }
}

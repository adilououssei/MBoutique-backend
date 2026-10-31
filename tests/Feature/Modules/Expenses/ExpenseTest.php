<?php

namespace Tests\Feature\Modules\Expenses;

use App\Models\User;
use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Dépenses d'exploitation — docs/modules.md §Expenses. */
class ExpenseTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{owner: User, store: Store, register: CashRegister} */
    private function butcher(int $opening = 50000): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['caisse', 'depenses', 'employes']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => $opening])->assertStatus(201);

        return ['owner' => $owner, 'store' => $store, 'register' => $register];
    }

    private function url(Store $store, string $path = ''): string
    {
        return "/api/boutiques/{$store->id}/depenses{$path}";
    }

    public function test_an_expense_paid_from_the_register_leaves_it_and_comes_back_when_cancelled(): void
    {
        ['store' => $store, 'register' => $register] = $this->butcher();

        $id = $this->postJson($this->url($store), ['categorie' => 'glace', 'libelle' => 'Pains de glace', 'montant' => 3000, 'mode' => 'caisse', 'caisse_id' => $register->id])
            ->assertStatus(201)
            ->assertJsonPath('donnees.libelle_categorie', 'Glace')
            ->assertJsonPath('donnees.montant', '3000.00')
            ->json('donnees.id');

        $out = CashMovement::where('type', 'sortie')->firstOrFail();
        $this->assertSame('-3000.00', (string) $out->montant);
        $this->assertSame('depense', $out->reference_type);

        $this->postJson($this->url($store, "/{$id}/annuler"), ['motif' => 'Saisie en double'])
            ->assertStatus(200)->assertJsonPath('donnees.statut', 'annulee');
        $this->assertSame('3000.00', (string) CashMovement::where('type', 'entree')->value('montant'));
        $this->postJson($this->url($store, "/{$id}/annuler"), ['motif' => 'Encore'])
            ->assertStatus(422)->assertJsonPath('code', 'DEPENSE_DEJA_ANNULEE');
    }

    public function test_cash_errors_write_nothing(): void
    {
        ['store' => $store, 'register' => $register] = $this->butcher(opening: 1000);
        $closed = CashRegister::factory()->for($store)->create();

        $this->postJson($this->url($store), ['categorie' => 'loyer', 'libelle' => 'Loyer', 'montant' => 5000, 'mode' => 'caisse', 'caisse_id' => $register->id])
            ->assertStatus(422)->assertJsonPath('code', 'SOLDE_CAISSE_INSUFFISANT');
        $this->postJson($this->url($store), ['categorie' => 'loyer', 'libelle' => 'Loyer', 'montant' => 500, 'mode' => 'caisse', 'caisse_id' => $closed->id])
            ->assertStatus(422)->assertJsonPath('code', 'AUCUNE_SESSION_CAISSE_OUVERTE');
        $this->postJson($this->url($store), ['categorie' => 'loyer', 'libelle' => 'Loyer', 'montant' => 500, 'mode' => 'caisse'])->assertStatus(422);

        $this->assertSame(0, Expense::count());
    }

    public function test_the_period_summary_groups_by_category_and_adds_salaries_paid_to_employees(): void
    {
        ['store' => $store] = $this->butcher();
        foreach ([['electricite', 25000], ['glace', 3000], ['glace', 2000], ['transport', 4000]] as [$category, $amount]) {
            $this->postJson($this->url($store), ['categorie' => $category, 'libelle' => $category, 'montant' => $amount, 'mode' => 'externe'])->assertStatus(201);
        }
        $cancelled = $this->postJson($this->url($store), ['categorie' => 'glace', 'libelle' => 'Erreur', 'montant' => 9999, 'mode' => 'externe'])->json('donnees.id');
        $this->postJson($this->url($store, "/{$cancelled}/annuler"), ['motif' => 'Erreur'])->assertStatus(200);

        // Un salaire versé depuis le module Employés : lu, jamais ressaisi.
        $employee = $this->postJson("/api/boutiques/{$store->id}/employes", ['nom' => 'Moussa'])->json('donnees.id');
        $this->postJson("/api/boutiques/{$store->id}/employes/{$employee}/paiements", ['type' => 'salaire', 'montant' => 60000, 'mode' => 'externe'])->assertStatus(201);

        $this->getJson($this->url($store, '/resume'))
            ->assertStatus(200)
            ->assertJsonPath('donnees.total_depenses', '34000.00')
            ->assertJsonPath('donnees.salaires_employes', '60000.00')
            ->assertJsonPath('donnees.total', '94000.00')
            ->assertJsonPath('donnees.par_categorie.0.categorie', 'electricite')
            ->assertJsonPath('donnees.par_categorie.1.total', '5000.00')
            ->assertJsonPath('donnees.par_categorie.1.nombre', 2);

        $this->getJson($this->url($store, '?categorie=glace'))->assertStatus(200)->assertJsonCount(3, 'donnees');
    }

    public function test_rights_and_isolation(): void
    {
        ['owner' => $owner, 'store' => $store, 'register' => $register] = $this->butcher();
        $id = $this->postJson($this->url($store), ['categorie' => 'eau', 'libelle' => 'Facture eau', 'montant' => 8000, 'mode' => 'externe'])->json('donnees.id');

        $cashier = User::factory()->create();
        $employee = User::factory()->create();
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $employee->email, 'role' => 'employe'])->assertStatus(201);

        Sanctum::actingAs($cashier);
        $this->postJson($this->url($store), ['categorie' => 'glace', 'libelle' => 'Glace', 'montant' => 1000, 'mode' => 'caisse', 'caisse_id' => $register->id])->assertStatus(201);
        $this->postJson($this->url($store, "/{$id}/annuler"), ['motif' => 'Non'])->assertStatus(403);

        Sanctum::actingAs($employee);
        $this->getJson($this->url($store))->assertStatus(403);

        Sanctum::actingAs($owner);
        ['store' => $grocery] = $this->createStoreWithFeatures(['caisse']);
        $this->getJson($this->url($grocery))->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');
        $this->getJson("/api/boutiques/{$grocery->id}/depenses/{$id}")->assertStatus(404);
    }
}

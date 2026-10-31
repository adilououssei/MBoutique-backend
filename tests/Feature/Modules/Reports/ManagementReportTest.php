<?php

namespace Tests\Feature\Modules\Reports;

use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Reports\Enums\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Rapport de gestion (boucherie) — docs/modules.md §Reports. */
class ManagementReportTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_month_of_butchery_gives_exact_figures(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00');
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures([
            'produits', 'categories', 'stock', 'ventes', 'caisse', 'clients', 'fournisseurs', 'employes',
            'decoupe', 'conservation', 'depenses', 'rapports', 'rapports_gestion',
        ]);
        Sanctum::actingAs($owner);
        $base = "/api/boutiques/{$store->id}";
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("{$base}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);

        $beef = Category::factory()->for($store)->create(['nom' => 'Bœuf']);
        $carcass = Product::factory()->for($store)->create(['nom' => 'Carcasse', 'unite' => 'kg', 'vente_detail_active' => false, 'prix_detail' => null, 'vente_gros_active' => false]);
        $filet = Product::factory()->for($store)->create(['nom' => 'Filet', 'unite' => 'kg', 'categorie_id' => $beef->id, 'prix_achat' => null, 'vente_detail_active' => true, 'prix_detail' => 7000, 'vente_gros_active' => false]);
        $ragout = Product::factory()->for($store)->create(['nom' => 'Ragoût', 'unite' => 'kg', 'categorie_id' => null, 'prix_achat' => null, 'vente_detail_active' => true, 'prix_detail' => 3500, 'vente_gros_active' => false]);

        // Carcasse de 100 kg achetée 250 000, dont 100 000 réglés : 150 000 de dette fournisseur.
        $supplier = $this->postJson("{$base}/fournisseurs", ['nom' => 'Abattoir'])->json('donnees.id');
        $this->postJson("{$base}/achats", [
            'fournisseur_id' => $supplier,
            'lignes' => [['produit_id' => $carcass->id, 'quantite' => 100, 'cout_unitaire' => 2500]],
            'paiement' => ['montant' => 100000, 'mode' => 'externe'],
        ])->assertStatus(201);

        // Découpe : 20 kg de filet (valeur 140 000) + 65 kg de ragoût (227 500), 15 kg de perte.
        // Coûts de revient : filet 95 238,10 / 20 = 4 761,905 → 4 761,91/kg, ragoût 2 380,95/kg.
        $this->postJson("{$base}/decoupes", [
            'entrees' => [['produit_id' => $carcass->id, 'quantite' => 100]],
            'sorties' => [['produit_id' => $filet->id, 'quantite' => 20], ['produit_id' => $ragout->id, 'quantite' => 65]],
        ])->assertStatus(201);

        // Ventes : 2,5 kg de filet comptant (17 500), 4 kg de ragoût à crédit (14 000).
        $this->postJson("{$base}/ventes/encaisser", ['caisse_id' => $register->id, 'lignes' => [['produit_id' => $filet->id, 'mode_prix' => 'detail', 'quantite' => 2.5]]])->assertStatus(201);
        $customer = Customer::factory()->for($store)->create();
        $this->postJson("{$base}/ventes/encaisser", [
            'caisse_id' => $register->id, 'client_id' => $customer->id, 'mode_paiement' => 'credit', 'acompte' => 0,
            'lignes' => [['produit_id' => $ragout->id, 'mode_prix' => 'detail', 'quantite' => 4]],
        ])->assertStatus(201);

        // Dépense de glace 3 000, salaire 20 000, 1 kg de ragoût avarié (2 380,95).
        $this->postJson("{$base}/depenses", ['categorie' => 'glace', 'libelle' => 'Glace', 'montant' => 3000, 'mode' => 'externe'])->assertStatus(201);
        $employee = $this->postJson("{$base}/employes", ['nom' => 'Moussa'])->json('donnees.id');
        $this->postJson("{$base}/employes/{$employee}/paiements", ['type' => 'salaire', 'montant' => 20000, 'mode' => 'externe'])->assertStatus(201);
        $this->postJson("{$base}/stocks/{$ragout->id}/mouvements", ['type' => 'perte', 'quantite' => 1, 'origine_perte' => 'avarie'])->assertStatus(201);

        $this->getJson("{$base}/rapports/gestion?periode=ce_mois")
            ->assertStatus(200)
            ->assertJsonPath('donnees.ventes.chiffre_affaires', '31500.00')
            ->assertJsonPath('donnees.ventes.poids_vendu_kg', '6.500')
            // Coût : 2,5 × 4 761,91 + 4 × 2 380,95 = 21 428,575.
            ->assertJsonPath('donnees.ventes.cout_marchandises', '21428.58')
            ->assertJsonPath('donnees.ventes.marge_commerciale', '10071.43')
            ->assertJsonPath('donnees.ventes.par_categorie.0.nom', 'Bœuf')
            ->assertJsonPath('donnees.ventes.par_categorie.0.chiffre_affaires', '17500.00')
            ->assertJsonPath('donnees.ventes.par_categorie.1.nom', 'Sans catégorie')
            ->assertJsonPath('donnees.ventes.par_produit.0.nom', 'Filet')
            ->assertJsonPath('donnees.ventes.par_produit.0.quantite', '2.500')
            // Stock : 17,5 kg × 4 761,91 + 60 kg × 2 380,95 = 226 190,425.
            ->assertJsonPath('donnees.stock.valeur', '226190.43')
            ->assertJsonPath('donnees.stock.poids_kg', '77.500')
            ->assertJsonPath('donnees.achats.total', '250000.00')
            ->assertJsonPath('donnees.achats.dettes_fournisseurs', '150000.00')
            ->assertJsonPath('donnees.creances_clients.total', '14000.00')
            ->assertJsonPath('donnees.creances_clients.clients_debiteurs', 1)
            ->assertJsonPath('donnees.depenses.depenses', '3000.00')
            ->assertJsonPath('donnees.depenses.salaires_employes', '20000.00')
            ->assertJsonPath('donnees.depenses.total', '23000.00')
            ->assertJsonPath('donnees.pertes.valeur_stock', '2380.95')
            ->assertJsonPath('donnees.pertes.decoupe.poids_kg', '15.000')
            ->assertJsonPath('donnees.pertes.decoupe.valeur', '37500.00')
            // 10 071,43 − 23 000 − 2 380,95 (la perte de découpe est déjà dans les coûts de revient).
            ->assertJsonPath('donnees.resultat.benefice_net_estime', '-15309.52');
    }

    public function test_the_week_starts_on_monday_and_other_domains_have_no_management_report(): void
    {
        $wednesday = CarbonImmutable::parse('2026-11-04 10:00:00');
        [$start] = ReportPeriod::ThisWeek->range($wednesday);
        [$previousStart, $previousEnd] = ReportPeriod::ThisWeek->previousRange($wednesday);
        $this->assertSame('2026-11-02 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-26 00:00:00', $previousStart->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-28 10:00:00', $previousEnd->format('Y-m-d H:i:s'));

        ['store' => $grocery] = $this->createStoreWithFeatures(['produits', 'ventes', 'rapports']);
        $this->getJson("/api/boutiques/{$grocery->id}/rapports/gestion")->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');
        $this->getJson("/api/boutiques/{$grocery->id}/rapports/tableau-de-bord?periode=cette_semaine")->assertStatus(200);
    }
}

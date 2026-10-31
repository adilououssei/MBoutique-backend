<?php

namespace Tests\Feature\Modules\Conservation;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Conservation\Models\StockLot;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Store;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Pharmacie : lots fiables, péremptions, ventes contrôlées, commandes, ordonnances, quarantaine. */
class PharmacyTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private const FEATURES = ['produits', 'categories', 'stock', 'conservation', 'lots_stricts', 'conditionnement', 'ventes', 'retours', 'caisse', 'clients', 'ordonnances', 'fournisseurs', 'commandes_fournisseurs', 'rapports', 'rapports_gestion'];

    /** @return array{owner: User, store: Store, register: CashRegister, para: Product, supplier: Supplier} */
    private function pharmacy(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(self::FEATURES);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson($this->url($store, "caisses/{$register->id}/sessions"), ['montant_ouverture' => 50000])->assertStatus(201);
        $id = $this->postJson($this->url($store, 'produits'), [
            'nom' => 'Paracétamol 500 mg', 'dci' => 'Paracétamol', 'forme' => 'Comprimé', 'dosage' => '500 mg', 'laboratoire' => 'Sanofi',
            'unites_par_boite' => 10, 'prix_achat' => 50, 'vente_detail_active' => true, 'prix_detail' => 100, 'vente_gros_active' => true, 'prix_gros' => 900,
        ])->assertStatus(201)->assertJsonPath('donnees.unites_par_boite', 10)->json('donnees.id');
        $supplier = Supplier::factory()->for($store)->create(['nom' => 'Laborex']);

        return ['owner' => $owner, 'store' => $store, 'register' => $register, 'para' => Product::query()->findOrFail($id), 'supplier' => $supplier];
    }

    private function url(Store $store, string $path): string
    {
        return "/api/boutiques/{$store->id}/{$path}";
    }

    /** Deux lots reçus : A périme dans 20 jours, B dans 200 jours. @return array{0: StockLot, 1: StockLot} */
    private function receiveTwoLots(Store $store, Product $product, Supplier $supplier): array
    {
        $this->postJson($this->url($store, 'achats'), [
            'fournisseur_id' => $supplier->id, 'numero_facture_fournisseur' => 'F-001',
            'lignes' => [
                ['produit_id' => $product->id, 'quantite' => 30, 'cout_unitaire' => 50, 'numero_lot' => 'A123', 'date_peremption' => now()->addDays(20)->toDateString(), 'fabrique_le' => now()->subYear()->toDateString()],
                ['produit_id' => $product->id, 'quantite' => 50, 'cout_unitaire' => 55, 'numero_lot' => 'B456', 'date_peremption' => now()->addDays(200)->toDateString()],
            ],
        ])->assertStatus(201);

        return [StockLot::query()->where('numero_lot', 'A123')->firstOrFail(), StockLot::query()->where('numero_lot', 'B456')->firstOrFail()];
    }

    public function test_a_pharmacy_gets_its_features_and_products_are_found_by_dci(): void
    {
        $this->seed(FeatureSeeder::class);
        $store = Store::factory()->create(['domaine_activite_id' => BusinessDomain::where('slug', 'pharmacie')->value('id')]);
        foreach (['conservation', 'lots_stricts', 'conditionnement', 'commandes_fournisseurs', 'ordonnances', 'retours', 'depenses', 'rapports_gestion'] as $slug) {
            $this->assertTrue(app(FeatureGate::class)->allows($store, $slug), $slug);
        }
        $butcher = Store::factory()->create(['domaine_activite_id' => BusinessDomain::where('slug', 'boucherie')->value('id')]);
        $this->assertFalse(app(FeatureGate::class)->allows($butcher, 'lots_stricts'));

        ['store' => $pharmacy] = $this->pharmacy();
        $this->getJson($this->url($pharmacy, 'produits?recherche=paracét'))->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.laboratoire', 'Sanofi');
    }

    public function test_received_lots_keep_their_identity_and_sales_follow_fefo_and_are_traced(): void
    {
        ['store' => $store, 'register' => $register, 'para' => $para, 'supplier' => $supplier] = $this->pharmacy();
        [$a, $b] = $this->receiveTwoLots($store, $para, $supplier);
        $this->assertSame('Laborex', $a->supplier->nom);
        $this->assertSame('50.00', $a->prix_achat);

        // 1 boîte de 10 : sort 10 unités du lot qui périme le plus tôt (A).
        $sale = $this->postJson($this->url($store, 'ventes/encaisser'), ['caisse_id' => $register->id, 'lignes' => [['produit_id' => $para->id, 'mode_prix' => 'gros', 'quantite' => 1]]])
            ->assertStatus(201)->assertJsonPath('donnees.lignes.0.lots.0.numero', 'A123')->json('donnees');
        $this->assertSame('20.000', $a->fresh()->quantite_restante);
        $this->getJson($this->url($store, "stocks/{$para->id}"))->assertJsonPath('donnees.quantite', '70.000');

        // Le vendeur choisit le lot B.
        $this->postJson($this->url($store, 'ventes/encaisser'), ['caisse_id' => $register->id, 'lignes' => [['produit_id' => $para->id, 'mode_prix' => 'detail', 'quantite' => 5, 'lot_id' => $b->id]]])->assertStatus(201);
        $this->assertSame('45.000', $b->fresh()->quantite_restante);

        // Annulation : les unités reviennent dans le lot A.
        $this->postJson($this->url($store, "ventes/{$sale['id']}/annuler"), ['motif' => 'Erreur', 'caisse_id' => $register->id])->assertOk();
        $this->assertSame('30.000', $a->fresh()->quantite_restante);
        $this->getJson($this->url($store, "lots/{$a->id}"))->assertOk()->assertJsonPath('donnees.ventes.0.quantite', '10.000');
    }

    public function test_expired_blocked_or_recalled_lots_are_never_sold(): void
    {
        ['store' => $store, 'register' => $register, 'para' => $para, 'supplier' => $supplier] = $this->pharmacy();
        [$a, $b] = $this->receiveTwoLots($store, $para, $supplier);
        $a->update(['date_limite' => now()->subDay()->toDateString()]);

        $sell = fn (array $line) => $this->postJson($this->url($store, 'ventes/encaisser'), ['caisse_id' => $register->id, 'lignes' => [['produit_id' => $para->id, 'mode_prix' => 'detail', ...$line]]]);
        $sell(['quantite' => 1, 'lot_id' => $a->id])->assertStatus(422)->assertJsonPath('code', 'LOT_NON_VENDABLE');
        // Sans lot désigné : le lot périmé est sauté.
        $sell(['quantite' => 2])->assertStatus(201);
        $this->assertSame('30.000', $a->fresh()->quantite_restante);

        $this->postJson($this->url($store, "lots/{$b->id}/statut"), ['statut' => 'rappele'])->assertStatus(422);
        $this->postJson($this->url($store, "lots/{$b->id}/statut"), ['statut' => 'rappele', 'motif' => 'Rappel ANSM'])->assertOk()->assertJsonPath('donnees.vendable', false);
        $sell(['quantite' => 1])->assertStatus(422)->assertJsonPath('code', 'LOT_NON_VENDABLE');
        $this->getJson($this->url($store, "lots/{$b->id}"))->assertJsonPath('donnees.journal.0.action', 'rappel');
        $this->getJson($this->url($store, 'lots/alertes'))->assertJsonPath('donnees.rappeles', 1)->assertJsonPath('donnees.perimes', 1);
    }

    public function test_expiry_thresholds_drive_the_tracking_table_and_the_report(): void
    {
        ['store' => $store, 'para' => $para, 'supplier' => $supplier] = $this->pharmacy();
        $this->receiveTwoLots($store, $para, $supplier);
        $this->putJson($this->url($store, 'parametres/peremption'), ['seuils' => [90, 30]])->assertOk()->assertJsonPath('donnees.seuils', [30, 90]);

        $this->getJson($this->url($store, 'lots/peremptions?seuil=30'))->assertOk()->assertJsonCount(1, 'donnees.lots')
            ->assertJsonPath('donnees.lots.0.numero_lot', 'A123')->assertJsonPath('donnees.valeur_au_prix_achat', '1500.00');
        $this->getJson($this->url($store, 'lots/alertes'))->assertJsonPath('donnees.par_seuil.30', 1);

        $report = $this->getJson($this->url($store, 'rapports/gestion?periode=aujourdhui'))->assertOk()->json('donnees');
        $this->assertSame(1, $report['peremptions']['a_venir'][0]['lots']);
        $this->assertSame('Laborex', $report['approvisionnements']['par_fournisseur'][0]['nom']);
    }

    public function test_an_order_enters_stock_only_once_received(): void
    {
        ['store' => $store, 'para' => $para, 'supplier' => $supplier] = $this->pharmacy();
        $order = $this->postJson($this->url($store, 'achats'), ['fournisseur_id' => $supplier->id, 'commande' => true, 'remise_fournisseur' => 100, 'lignes' => [['produit_id' => $para->id, 'quantite' => 100, 'cout_unitaire' => 50]]])
            ->assertStatus(201)->assertJsonPath('donnees.statut', 'commande')->json('donnees');
        $this->assertSame(0, StockLot::query()->count());
        $this->getJson($this->url($store, "fournisseurs/{$supplier->id}"))->assertJsonPath('donnees.solde_du', '0.00');
        $this->postJson($this->url($store, "achats/{$order['id']}/paiements"), ['montant' => 100, 'mode' => 'externe'])->assertStatus(422);

        // 80 reçues sur 100, lot et date vérifiés.
        $this->postJson($this->url($store, "achats/{$order['id']}/reception"), ['lignes' => [['ligne_id' => $order['lignes'][0]['id'], 'quantite' => 80, 'numero_lot' => 'C789', 'date_peremption' => now()->addYear()->toDateString()]]])
            ->assertOk()->assertJsonPath('donnees.statut', 'recu')->assertJsonPath('donnees.montant_total', '3900.00');
        $this->assertSame('80.000', StockLot::query()->where('numero_lot', 'C789')->value('quantite_restante'));
        $this->postJson($this->url($store, "achats/{$order['id']}/reception"), ['lignes' => [['ligne_id' => $order['lignes'][0]['id'], 'quantite' => 1]]])->assertStatus(422);
    }

    public function test_prescription_only_products_need_a_prescription_with_partial_deliveries(): void
    {
        ['store' => $store, 'register' => $register, 'supplier' => $supplier] = $this->pharmacy();
        $amox = $this->postJson($this->url($store, 'produits'), ['nom' => 'Amoxicilline 1 g', 'sur_ordonnance' => true, 'vente_detail_active' => true, 'prix_detail' => 2500])->json('donnees.id');
        $this->postJson($this->url($store, 'achats'), ['fournisseur_id' => $supplier->id, 'lignes' => [['produit_id' => $amox, 'quantite' => 10, 'cout_unitaire' => 1500, 'numero_lot' => 'AMX1', 'date_peremption' => now()->addYear()->toDateString()]]])->assertStatus(201);
        $sell = fn (array $extra) => $this->postJson($this->url($store, 'ventes/encaisser'), ['caisse_id' => $register->id, 'lignes' => [['produit_id' => $amox, 'mode_prix' => 'detail', 'quantite' => 2]], ...$extra]);

        $sell([])->assertStatus(422)->assertJsonPath('code', 'ORDONNANCE_REQUISE');
        $prescription = $this->postJson($this->url($store, 'ordonnances'), [
            'nom_patient' => 'A. Traoré', 'prescripteur' => 'Dr Koné', 'prescrite_le' => now()->toDateString(),
            'lignes' => [['produit_id' => $amox, 'posologie' => '1 g matin et soir', 'quantite_prescrite' => 3]],
        ])->assertStatus(201)->assertJsonPath('donnees.statut', 'en_attente')->json('donnees.id');

        $first = $sell(['ordonnance_id' => $prescription])->assertStatus(201)->json('donnees.id');
        $this->getJson($this->url($store, "ordonnances/{$prescription}"))->assertJsonPath('donnees.statut', 'partielle')->assertJsonPath('donnees.lignes.0.reste', '1.000');
        $sell(['ordonnance_id' => $prescription])->assertStatus(422)->assertJsonPath('code', 'ORDONNANCE_INSUFFISANTE');

        // Annuler la vente rend les quantités à délivrer.
        $this->postJson($this->url($store, "ventes/{$first}/annuler"), ['motif' => 'Erreur', 'caisse_id' => $register->id])->assertOk();
        $this->getJson($this->url($store, "ordonnances/{$prescription}"))->assertJsonPath('donnees.statut', 'en_attente');

        // Un employé simple n'accède pas aux ordonnances.
        $employee = User::factory()->create();
        $this->postJson($this->url($store, 'membres'), ['email' => $employee->email, 'role' => 'employe'])->assertStatus(201);
        Sanctum::actingAs($employee);
        $this->getJson($this->url($store, 'ordonnances'))->assertStatus(403);
    }

    public function test_a_returned_medicine_stays_in_quarantine_until_checked(): void
    {
        ['store' => $store, 'register' => $register, 'para' => $para, 'supplier' => $supplier] = $this->pharmacy();
        $this->receiveTwoLots($store, $para, $supplier);
        $sale = $this->postJson($this->url($store, 'ventes/encaisser'), ['caisse_id' => $register->id, 'lignes' => [['produit_id' => $para->id, 'mode_prix' => 'gros', 'quantite' => 2]]])->json('donnees');

        $return = $this->postJson($this->url($store, 'retours'), [
            'vente_id' => $sale['id'], 'lignes' => [['ligne_vente_id' => $sale['lignes'][0]['id'], 'quantite' => 1]],
            'motif' => 'Boîte non ouverte', 'decision' => 'remboursement', 'etat_produits' => 'a_verifier', 'caisse_id' => $register->id,
        ])->assertStatus(201)->assertJsonPath('donnees.statut_verification', 'a_verifier')->json('donnees.id');
        $this->getJson($this->url($store, "stocks/{$para->id}"))->assertJsonPath('donnees.quantite', '60.000');

        $this->postJson($this->url($store, "retours/{$return}/verification"), ['decision' => 'reintegrer'])->assertOk()->assertJsonPath('donnees.statut_verification', 'reintegre');
        $this->getJson($this->url($store, "stocks/{$para->id}"))->assertJsonPath('donnees.quantite', '70.000');
        $this->postJson($this->url($store, "retours/{$return}/verification"), ['decision' => 'detruire'])->assertStatus(422)->assertJsonPath('code', 'RETOUR_DEJA_VERIFIE');
    }
}

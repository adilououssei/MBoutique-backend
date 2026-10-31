<?php

namespace Tests\Feature\Modules\Reports;

use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Devices\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Rapport de gestion d'un magasin d'électronique : marques, marges, vendeurs, garanties, réparations. */
class ElectronicsReportTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    public function test_brands_margins_sellers_warranties_and_repairs_are_reported(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures([
            'produits', 'stock', 'ventes', 'caisse', 'clients', 'marques_modeles', 'appareils', 'garanties', 'reparations', 'rapports', 'rapports_gestion',
        ]);
        Sanctum::actingAs($owner);
        $base = "/api/boutiques/{$store->id}";
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("{$base}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);

        $phone = Product::factory()->for($store)->create(['nom' => 'Galaxy A15', 'marque' => 'Samsung', 'suivi_unitaire' => true, 'garantie_mois' => 12, 'prix_achat' => 90000, 'vente_detail_active' => true, 'prix_detail' => 120000, 'vente_gros_active' => false]);
        $cable = Product::factory()->for($store)->create(['nom' => 'Câble USB-C', 'marque' => 'Anker', 'prix_achat' => 2000, 'vente_detail_active' => true, 'prix_detail' => 5000, 'vente_gros_active' => false]);
        $screen = Product::factory()->for($store)->create(['nom' => 'Écran A15', 'prix_achat' => 15000, 'vente_detail_active' => true, 'prix_detail' => 25000, 'vente_gros_active' => false]);
        $this->postJson("{$base}/appareils", ['produit_id' => $phone->id, 'identifiants' => ['356938035643809', '356938035643817'], 'ajouter_au_stock' => true])->assertStatus(201);
        foreach ([[$cable, 10], [$screen, 3]] as [$product, $quantity]) {
            $this->postJson("{$base}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => $quantity])->assertStatus(201);
        }
        [$sold, $broken] = Device::orderBy('id')->get()->all();

        $this->postJson("{$base}/ventes/encaisser", ['caisse_id' => $register->id, 'lignes' => [['produit_id' => $phone->id, 'mode_prix' => 'detail', 'quantite' => 1, 'appareil_ids' => [$sold->id]]]])->assertStatus(201);
        $this->postJson("{$base}/ventes/encaisser", ['caisse_id' => $register->id, 'mode_paiement' => 'mobile_money', 'lignes' => [['produit_id' => $cable->id, 'mode_prix' => 'detail', 'quantite' => 2]]])->assertStatus(201);
        $this->postJson("{$base}/appareils/{$broken->id}/statut", ['statut' => 'defectueux', 'motif' => 'Écran mort'])->assertStatus(200);

        $repair = $this->postJson("{$base}/reparations", ['appareil' => 'Galaxy A15', 'panne' => 'Écran cassé', 'cout_final' => 32000])->json('donnees.id');
        $this->postJson("{$base}/reparations/{$repair}/pieces", ['produit_id' => $screen->id, 'quantite' => 1])->assertStatus(201);
        $this->postJson("{$base}/reparations/{$repair}/paiements", ['montant' => 32000, 'mode' => 'mobile_money'])->assertStatus(201);

        $this->getJson("{$base}/rapports/gestion?periode=ce_mois")
            ->assertStatus(200)
            ->assertJsonPath('donnees.ventes.chiffre_affaires', '130000.00')
            ->assertJsonPath('donnees.ventes.marge_commerciale', '36000.00')
            ->assertJsonPath('donnees.ventes.par_marque.0.marque', 'Samsung')
            ->assertJsonPath('donnees.ventes.par_marque.1.chiffre_affaires', '10000.00')
            ->assertJsonPath('donnees.ventes.par_produit.0.marge', '30000.00')
            ->assertJsonPath('donnees.ventes.par_vendeur.0.nombre_ventes', 2)
            ->assertJsonPath('donnees.stock.appareils_defectueux', 1)
            ->assertJsonPath('donnees.stock.appareils_en_stock', 0)
            // 8 câbles × 2 000 + 2 écrans × 15 000 = 46 000 (les téléphones sont vendus ou défectueux).
            ->assertJsonPath('donnees.stock.valeur', '46000.00')
            ->assertJsonPath('donnees.pertes.valeur_stock', '90000.00')
            ->assertJsonPath('donnees.garanties.actives', 1)
            ->assertJsonPath('donnees.reparations.revenus', '32000.00')
            ->assertJsonPath('donnees.reparations.cout_pieces', '15000.00')
            ->assertJsonPath('donnees.resultat.marge_reparations', '17000.00')
            // 36 000 − 0 − 90 000 + 17 000.
            ->assertJsonPath('donnees.resultat.benefice_net_estime', '-37000.00');

        // Stock filtré par marque.
        $this->getJson("{$base}/stocks?marque=Anker")->assertJsonCount(1, 'donnees');
    }
}

<?php

namespace Tests\Feature\Modules\Suppliers;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Facture fournisseur et frais annexes répartis dans le coût de revient — docs/modules.md §Suppliers. */
class PurchaseLandedCostTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    public function test_extra_costs_are_spread_into_the_cost_price_but_not_into_the_supplier_debt(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock', 'fournisseurs']);
        Sanctum::actingAs($owner);
        $cable = Product::factory()->for($store)->create(['nom' => 'Câble']);
        $headset = Product::factory()->for($store)->create(['nom' => 'Casque']);
        $supplier = $this->postJson("/api/boutiques/{$store->id}/fournisseurs", ['nom' => 'Importateur'])->json('donnees.id');

        // 10 × 1 000 + 5 × 6 000 = 40 000 ; 10 000 de transport et douane.
        $purchase = $this->postJson("/api/boutiques/{$store->id}/achats", [
            'fournisseur_id' => $supplier,
            'numero_facture_fournisseur' => 'FA-2026-118',
            'reference_commande' => 'BC-77',
            'frais_annexes' => 10000,
            'detail_frais' => 'Transport 4 000, douane 6 000',
            'lignes' => [
                ['produit_id' => $cable->id, 'quantite' => 10, 'cout_unitaire' => 1000],
                ['produit_id' => $headset->id, 'quantite' => 5, 'cout_unitaire' => 6000],
            ],
        ])->assertStatus(201)
            ->assertJsonPath('donnees.montant_total', '40000.00')
            ->assertJsonPath('donnees.frais_annexes', '10000.00')
            ->assertJsonPath('donnees.reste_a_payer', '40000.00')
            ->json('donnees');

        // Câbles : 2 500 de frais / 10 → 1 250 ; casques : 7 500 / 5 → 7 500.
        $lines = collect($purchase['lignes'])->keyBy('nom_produit');
        $this->assertSame('1250.00', $lines['Câble']['cout_revient_unitaire']);
        $this->assertSame('7500.00', $lines['Casque']['cout_revient_unitaire']);
        $this->assertSame('1250.00', (string) $cable->refresh()->prix_achat);
        $this->assertSame('7500.00', (string) $headset->refresh()->prix_achat);

        $this->getJson("/api/boutiques/{$store->id}/achats?recherche=FA-2026")->assertJsonCount(1, 'donnees');
    }
}

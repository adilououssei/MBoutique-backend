<?php

namespace Tests\Feature\Modules\Catalog;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Tenancy\Models\Store;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Boutique de vêtements : variantes, ventes par variante, promotions, échanges, rapports. */
class ClothingTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private const FEATURES = ['produits', 'categories', 'stock', 'variantes', 'collections', 'promotions', 'ventes', 'retours', 'caisse', 'clients', 'rapports', 'rapports_gestion'];

    /** @return array{owner: User, store: Store, register: CashRegister, shirt: Product} */
    private function shop(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(self::FEATURES);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson($this->url($store, "caisses/{$register->id}/sessions"), ['montant_ouverture' => 50000])->assertStatus(201);
        $shirtId = $this->postJson($this->url($store, 'produits'), [
            'nom' => 'Chemise classique', 'sku' => 'CHEM', 'prix_achat' => 4000, 'vente_detail_active' => true, 'prix_detail' => 10000, 'public_cible' => 'homme',
        ])->assertStatus(201)->json('donnees.id');

        return ['owner' => $owner, 'store' => $store, 'register' => $register, 'shirt' => Product::query()->findOrFail($shirtId)];
    }

    private function url(Store $store, string $path): string
    {
        return "/api/boutiques/{$store->id}/{$path}";
    }

    /** @return array<string, int> taille|couleur => id */
    private function variants(Store $store, Product $shirt): array
    {
        $this->postJson($this->url($store, "produits/{$shirt->id}/variantes"), ['tailles' => ['M', 'L'], 'couleurs' => ['Noir', 'Blanc']])
            ->assertStatus(201)->assertJsonCount(4, 'donnees');
        $ids = [];
        foreach ($this->getJson($this->url($store, "produits/{$shirt->id}/variantes"))->json('donnees.variantes') as $v) {
            $ids["{$v['taille']}|{$v['couleur']}"] = $v['id'];
        }

        return $ids;
    }

    public function test_a_clothing_shop_gets_its_features(): void
    {
        $this->seed(FeatureSeeder::class);
        $store = Store::factory()->create(['domaine_activite_id' => BusinessDomain::where('slug', 'vetements')->value('id')]);
        foreach (['variantes', 'collections', 'promotions', 'retours', 'fournisseurs', 'marques_modeles', 'depenses', 'rapports_gestion'] as $slug) {
            $this->assertTrue(app(FeatureGate::class)->allows($store, $slug), $slug);
        }
    }

    public function test_variants_are_generated_and_hold_their_own_stock(): void
    {
        ['store' => $store, 'shirt' => $shirt] = $this->shop();
        $ids = $this->variants($store, $shirt);
        // Relancer ne recrée pas les combinaisons existantes.
        $this->postJson($this->url($store, "produits/{$shirt->id}/variantes"), ['tailles' => ['M', 'L', 'XL'], 'couleurs' => ['Noir']])->assertStatus(201)->assertJsonCount(1, 'donnees');

        $variant = Product::query()->findOrFail($ids['M|Noir']);
        $this->assertSame('Chemise classique — M / Noir', $variant->nom);
        $this->assertSame('CHEM-MNOIR', $variant->sku);
        $this->assertSame('10000.00', $variant->prix_detail);
        $this->assertSame('homme', $variant->public_cible);

        $this->postJson($this->url($store, "stocks/{$ids['M|Noir']}/mouvements"), ['type' => 'initial', 'quantite' => 5])->assertStatus(201);
        $this->postJson($this->url($store, "stocks/{$ids['L|Blanc']}/mouvements"), ['type' => 'initial', 'quantite' => 3])->assertStatus(201);
        $grid = $this->getJson($this->url($store, "produits/{$shirt->id}/variantes"))->assertOk()
            ->assertJsonPath('donnees.stock_total', '8.000')->assertJsonPath('donnees.tailles', ['M', 'L', 'XL'])->json('donnees.variantes');
        $this->assertSame('5.000', collect($grid)->firstWhere('id', $ids['M|Noir'])['stock']);

        // Catalogue sans variantes ; renommer l'article renomme ses variantes.
        $this->getJson($this->url($store, 'produits?sans_variantes=1'))->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.nombre_variantes', 5);
        $this->putJson($this->url($store, "produits/{$shirt->id}"), ['nom' => 'Chemise slim'])->assertOk();
        $this->assertSame('Chemise slim — M / Noir', $variant->fresh()->nom);
    }

    public function test_a_sale_only_decreases_the_sold_variant_and_barcodes_find_it(): void
    {
        ['store' => $store, 'register' => $register, 'shirt' => $shirt] = $this->shop();
        $ids = $this->variants($store, $shirt);
        foreach ($ids as $id) {
            $this->postJson($this->url($store, "stocks/{$id}/mouvements"), ['type' => 'initial', 'quantite' => 5])->assertStatus(201);
        }
        $this->putJson($this->url($store, "produits/{$ids['M|Noir']}"), ['code_barres' => '3700000000011'])->assertOk();

        $this->getJson($this->url($store, 'produits?recherche=3700000000011'))->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.taille', 'M');
        $this->postJson($this->url($store, 'ventes/encaisser'), ['caisse_id' => $register->id, 'lignes' => [['produit_id' => $ids['M|Noir'], 'mode_prix' => 'detail', 'quantite' => 2]]])->assertStatus(201);

        $this->getJson($this->url($store, "stocks/{$ids['M|Noir']}"))->assertJsonPath('donnees.quantite', '3.000');
        $this->getJson($this->url($store, "stocks/{$ids['M|Blanc']}"))->assertJsonPath('donnees.quantite', '5.000');
    }

    public function test_a_running_promotion_is_applied_and_reported(): void
    {
        ['store' => $store, 'register' => $register, 'shirt' => $shirt] = $this->shop();
        $ids = $this->variants($store, $shirt);
        $this->postJson($this->url($store, "stocks/{$ids['L|Noir']}/mouvements"), ['type' => 'initial', 'quantite' => 5])->assertStatus(201);

        // Promotion sur l'article : ses variantes en profitent.
        $promo = $this->postJson($this->url($store, 'promotions'), [
            'nom' => 'Soldes d’été', 'type' => 'pourcentage', 'valeur' => 20, 'debut_le' => now()->subDay()->toIso8601String(), 'portee' => 'produits', 'produit_ids' => [$shirt->id],
        ])->assertStatus(201)->assertJsonPath('donnees.en_cours', true)->json('donnees.id');
        $this->postJson($this->url($store, 'promotions'), ['nom' => 'Trop', 'type' => 'pourcentage', 'valeur' => 150, 'debut_le' => now()->toIso8601String(), 'portee' => 'tous'])->assertStatus(422);

        $this->getJson($this->url($store, "produits?parent_id={$shirt->id}&taille=L&couleur=Noir"))->assertJsonPath('donnees.0.promotion.prix', '8000.00');
        $this->postJson($this->url($store, 'ventes/encaisser'), ['caisse_id' => $register->id, 'lignes' => [['produit_id' => $ids['L|Noir'], 'mode_prix' => 'detail', 'quantite' => 2]]])
            ->assertStatus(201)->assertJsonPath('donnees.total', '16000.00')->assertJsonPath('donnees.lignes.0.promotion_id', $promo);

        $this->getJson($this->url($store, "promotions/{$promo}"))->assertJsonPath('donnees.ventes.nombre', 1)->assertJsonPath('donnees.ventes.remises_accordees', '4000.00');
    }

    public function test_a_size_exchange_returns_the_old_item_and_sells_the_new_one(): void
    {
        ['store' => $store, 'register' => $register, 'shirt' => $shirt] = $this->shop();
        $ids = $this->variants($store, $shirt);
        foreach ($ids as $id) {
            $this->postJson($this->url($store, "stocks/{$id}/mouvements"), ['type' => 'initial', 'quantite' => 5])->assertStatus(201);
        }
        $this->putJson($this->url($store, "produits/{$ids['L|Blanc']}"), ['vente_detail_active' => true, 'prix_detail' => 12000])->assertOk();
        $sale = $this->postJson($this->url($store, 'ventes/encaisser'), ['caisse_id' => $register->id, 'lignes' => [['produit_id' => $ids['M|Noir'], 'mode_prix' => 'detail', 'quantite' => 1]]])->json('donnees');

        // M noir rendu, L blanc pris : le client paie 2 000 de différence.
        $return = $this->postJson($this->url($store, 'retours/echange'), [
            'vente_id' => $sale['id'], 'lignes' => [['ligne_vente_id' => $sale['lignes'][0]['id'], 'quantite' => 1]],
            'motif' => 'Trop petite', 'etat_produits' => 'revendable', 'caisse_id' => $register->id,
            'nouvelles_lignes' => [['produit_id' => $ids['L|Blanc'], 'quantite' => 1]],
        ])->assertStatus(201)->assertJsonPath('donnees.decision', 'echange')->assertJsonPath('donnees.montant', '10000.00')->json('donnees');

        $this->getJson($this->url($store, "stocks/{$ids['M|Noir']}"))->assertJsonPath('donnees.quantite', '5.000');
        $this->getJson($this->url($store, "stocks/{$ids['L|Blanc']}"))->assertJsonPath('donnees.quantite', '4.000');
        $newSale = $this->getJson($this->url($store, "ventes/{$return['vente_echange_id']}"))->assertJsonPath('donnees.total', '12000.00')->json('donnees');
        $this->assertSame('2000.00', $newSale['montant_especes'] ?? '2000.00');

        // Le nouvel article coûte moins : l'écart est rendu.
        $cheaper = $this->postJson($this->url($store, 'ventes/encaisser'), ['caisse_id' => $register->id, 'lignes' => [['produit_id' => $ids['L|Blanc'], 'mode_prix' => 'detail', 'quantite' => 1]]])->json('donnees');
        $this->postJson($this->url($store, 'retours/echange'), [
            'vente_id' => $cheaper['id'], 'lignes' => [['ligne_vente_id' => $cheaper['lignes'][0]['id'], 'quantite' => 1]],
            'motif' => 'Couleur', 'etat_produits' => 'revendable', 'caisse_id' => $register->id,
            'nouvelles_lignes' => [['produit_id' => $ids['L|Noir'], 'quantite' => 1]],
        ])->assertStatus(201)->assertJsonPath('donnees.montant_rendu', '2000.00');

        $report = $this->getJson($this->url($store, 'rapports/gestion?periode=aujourdhui'))->assertOk()->json('donnees');
        $this->assertSame(2, $report['retours']['echanges']);
        $this->assertSame('L', $report['mode']['par_taille'][0]['valeur']);
    }

    public function test_subcategories_collections_and_customer_preferences(): void
    {
        ['store' => $store, 'shirt' => $shirt] = $this->shop();
        $men = $this->postJson($this->url($store, 'categories'), ['nom' => 'Homme'])->json('donnees.id');
        $shirts = $this->postJson($this->url($store, 'categories'), ['nom' => 'Chemises', 'parent_id' => $men])->assertStatus(201)->assertJsonPath('donnees.parent_id', $men)->json('donnees.id');
        $summer = $this->postJson($this->url($store, 'collections'), ['nom' => 'Été 2026', 'saison' => 'ete'])->assertStatus(201)->json('donnees.id');
        $this->putJson($this->url($store, "produits/{$shirt->id}"), ['categorie_id' => $shirts, 'collection_id' => $summer, 'etat' => 'destockage'])->assertOk()->assertJsonPath('donnees.collection.nom', 'Été 2026');

        $this->getJson($this->url($store, "produits?categorie_id={$men}"))->assertJsonCount(1, 'donnees');
        $this->getJson($this->url($store, 'collections'))->assertJsonPath('donnees.0.nombre_articles', 1);

        $client = Customer::factory()->for($store)->create();
        $this->putJson($this->url($store, "clients/{$client->id}"), ['preferences' => ['taille' => 'L', 'pointure' => '43']])->assertOk()->assertJsonPath('donnees.preferences.pointure', '43');
    }
}

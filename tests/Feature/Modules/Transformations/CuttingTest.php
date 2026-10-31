<?php

namespace Tests\Feature\Modules\Transformations;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Transformations\Models\Transformation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Découpe d'une carcasse — docs/modules.md §Transformations. */
class CuttingTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private const FEATURES = ['produits', 'stock', 'ventes', 'caisse', 'decoupe', 'preparations'];

    /** @return array{owner: User, store: Store, carcass: Product, filet: Product, entrecote: Product, ragout: Product, os: Product} */
    private function butcher(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(self::FEATURES);
        Sanctum::actingAs($owner);

        // Carcasse achetée 2 500 FCFA/kg : 100 kg = 250 000 FCFA.
        $carcass = $this->product($store, 'Carcasse de bœuf', null, 2500);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$carcass->id}/mouvements", ['type' => 'initial', 'quantite' => 100])->assertStatus(201);

        return [
            'owner' => $owner,
            'store' => $store,
            'carcass' => $carcass,
            'filet' => $this->product($store, 'Filet', 7000),
            'entrecote' => $this->product($store, 'Entrecôte', 5000),
            'ragout' => $this->product($store, 'Viande à ragoût', 3500),
            'os' => $this->product($store, 'Os à soupe', 500),
        ];
    }

    private function product(Store $store, string $name, ?int $salePrice, ?int $purchasePrice = null, string $unit = 'kg'): Product
    {
        return Product::factory()->for($store)->create([
            'nom' => $name,
            'unite' => $unit,
            'prix_achat' => $purchasePrice,
            'vente_detail_active' => $salePrice !== null,
            'prix_detail' => $salePrice,
            'vente_gros_active' => false,
            'prix_gros' => null,
        ]);
    }

    private function url(Store $store, string $path = ''): string
    {
        return "/api/boutiques/{$store->id}/decoupes{$path}";
    }

    private function cut(array $p, array $extra = []): array
    {
        return [
            'entrees' => [['produit_id' => $p['carcass']->id, 'quantite' => 100]],
            'sorties' => [
                ['produit_id' => $p['filet']->id, 'quantite' => 8],
                ['produit_id' => $p['entrecote']->id, 'quantite' => 12],
                ['produit_id' => $p['ragout']->id, 'quantite' => 50],
                ['produit_id' => $p['os']->id, 'quantite' => 15],
            ],
            ...$extra,
        ];
    }

    private function quantity(Product $product): float
    {
        return (float) Stock::where('produit_id', $product->id)->value('quantite');
    }

    public function test_a_carcass_is_cut_into_pieces_with_loss_cost_price_and_margin(): void
    {
        $p = $this->butcher();

        $response = $this->postJson($this->url($p['store']), $this->cut($p))
            ->assertStatus(201)
            ->assertJsonPath('donnees.type', 'decoupe')
            ->assertJsonPath('donnees.cout_total', '250000.00')
            ->assertJsonPath('donnees.poids_entree', '100.000')
            ->assertJsonPath('donnees.poids_sortie', '85.000')
            ->assertJsonPath('donnees.perte', '15.000')
            ->assertJsonPath('donnees.taux_perte', '15.0')
            ->assertJsonPath('donnees.methode_repartition', 'valeur')
            // Valeur de vente : 56 000 + 60 000 + 175 000 + 7 500 = 298 500.
            ->assertJsonPath('donnees.valeur_vente', '298500.00')
            ->assertJsonPath('donnees.marge_potentielle', '48500.00');

        $out = collect($response->json('donnees.sorties'))->keyBy('nom_produit');
        // 250 000 × 56 000 / 298 500 = 46 901,17 → 5 862,65 FCFA/kg de filet.
        $this->assertSame('46901.17', $out['Filet']['cout_total']);
        $this->assertSame('5862.65', $out['Filet']['cout_unitaire']);
        $this->assertSame('50251.26', $out['Entrecôte']['cout_total']);
        $this->assertSame('146566.16', $out['Viande à ragoût']['cout_total']);
        // Le dernier morceau prend l'arrondi : la somme fait exactement le coût total.
        $this->assertSame('6281.41', $out['Os à soupe']['cout_total']);
        $this->assertSame(250000.0, $out->sum(fn ($line) => (float) $line['cout_total']));
        $this->assertSame('9098.83', $out['Filet']['marge_potentielle']);

        // Transformation du stock, pas un achat : la carcasse sort, les morceaux entrent.
        $this->assertSame(0.0, $this->quantity($p['carcass']));
        $this->assertSame(8.0, $this->quantity($p['filet']));
        $this->assertSame(50.0, $this->quantity($p['ragout']));
        $this->assertSame(1, StockMovement::where('type', 'transformation_sortie')->count());
        $this->assertSame(4, StockMovement::where('type', 'transformation_entree')->count());
        $this->assertSame(0, StockMovement::where('type', 'achat')->count());
        $this->assertSame('transformation', StockMovement::where('type', 'transformation_entree')->value('reference_type'));
        $this->assertDatabaseCount('achats', 0);

        // Le coût de revient devient le prix d'achat du morceau (marges des rapports).
        $this->assertSame('5862.65', (string) $p['filet']->refresh()->prix_achat);
    }

    public function test_weight_allocation_spreads_the_cost_per_kilo(): void
    {
        $p = $this->butcher();

        $response = $this->postJson($this->url($p['store']), $this->cut($p, ['methode_repartition' => 'poids']))
            ->assertStatus(201)->assertJsonPath('donnees.methode_repartition', 'poids');

        // 250 000 / 85 kg = 2 941,18 FCFA/kg pour chaque morceau.
        $out = collect($response->json('donnees.sorties'))->keyBy('nom_produit');
        $this->assertSame('2941.18', $out['Filet']['cout_unitaire']);
        $this->assertSame('2941.18', $out['Os à soupe']['cout_unitaire']);
    }

    public function test_value_allocation_falls_back_to_weight_when_a_piece_has_no_sale_price(): void
    {
        $p = $this->butcher();
        $p['os']->update(['vente_detail_active' => false, 'prix_detail' => null]);

        $this->postJson($this->url($p['store']), $this->cut($p))
            ->assertStatus(201)
            ->assertJsonPath('donnees.methode_repartition', 'poids')
            ->assertJsonPath('donnees.valeur_vente', null);
    }

    public function test_an_entered_carcass_cost_and_extra_costs_replace_the_purchase_price(): void
    {
        $p = $this->butcher();
        $data = $this->cut($p, ['frais_annexes' => 5000]);
        $data['entrees'][0]['cout_total'] = 240000;

        $this->postJson($this->url($p['store']), $data)
            ->assertStatus(201)
            ->assertJsonPath('donnees.cout_matieres', '240000.00')
            ->assertJsonPath('donnees.frais_annexes', '5000.00')
            ->assertJsonPath('donnees.cout_total', '245000.00')
            ->assertJsonPath('donnees.entrees.0.cout_unitaire', '2400.00');
    }

    public function test_a_piece_sold_by_unit_counts_in_value_but_not_in_weight(): void
    {
        $p = $this->butcher();
        $head = $this->product($p['store'], 'Tête de bœuf', 6000, null, 'piece');
        $data = $this->cut($p);
        $data['sorties'][] = ['produit_id' => $head->id, 'quantite' => 1];

        $this->postJson($this->url($p['store']), $data)
            ->assertStatus(201)->assertJsonPath('donnees.poids_sortie', '85.000');

        $this->postJson($this->url($p['store']), [...$data, 'methode_repartition' => 'poids'])
            ->assertStatus(422)->assertJsonPath('code', 'TRANSFORMATION_INVALIDE');
    }

    public function test_errors_write_nothing(): void
    {
        $p = $this->butcher();

        // Plus de carcasse qu'en stock.
        $tooMuch = $this->cut($p);
        $tooMuch['entrees'][0]['quantite'] = 120;
        $this->postJson($this->url($p['store']), $tooMuch)->assertStatus(422)->assertJsonPath('code', 'STOCK_INSUFFISANT');

        // Coût inconnu.
        $p['carcass']->update(['prix_achat' => null]);
        $this->postJson($this->url($p['store']), $this->cut($p))->assertStatus(422)->assertJsonPath('code', 'COUT_INCONNU');

        // Un produit à la fois consommé et obtenu.
        $loop = $this->cut($p);
        $loop['sorties'][] = ['produit_id' => $p['carcass']->id, 'quantite' => 1];
        $loop['entrees'][0]['cout_total'] = 250000;
        $this->postJson($this->url($p['store']), $loop)->assertStatus(422)->assertJsonPath('code', 'TRANSFORMATION_INVALIDE');

        $this->assertSame(0, Transformation::count());
        $this->assertSame(100.0, $this->quantity($p['carcass']));
        $this->assertNull(Stock::where('produit_id', $p['filet']->id)->first());
    }

    public function test_cancelling_restores_the_stock_unless_pieces_were_sold(): void
    {
        $p = $this->butcher();
        $id = $this->postJson($this->url($p['store']), $this->cut($p))->json('donnees.id');

        $this->postJson($this->url($p['store'], "/{$id}/annuler"), ['motif' => 'Erreur de pesée'])
            ->assertStatus(200)->assertJsonPath('donnees.statut', 'annulee');
        $this->assertSame(100.0, $this->quantity($p['carcass']));
        $this->assertSame(0.0, $this->quantity($p['filet']));
        $this->postJson($this->url($p['store'], "/{$id}/annuler"), ['motif' => 'Encore'])
            ->assertStatus(422)->assertJsonPath('code', 'TRANSFORMATION_DEJA_ANNULEE');

        // Second découpage dont une partie du filet est vendue : annulation impossible.
        $again = $this->postJson($this->url($p['store']), $this->cut($p))->json('donnees.id');
        $this->postJson("/api/boutiques/{$p['store']->id}/stocks/{$p['filet']->id}/mouvements", ['type' => 'perte', 'quantite' => 1])->assertStatus(201);
        $this->postJson($this->url($p['store'], "/{$again}/annuler"), ['motif' => 'Trop tard'])
            ->assertStatus(422)->assertJsonPath('code', 'STOCK_INSUFFISANT');
        $this->assertSame('terminee', Transformation::findOrFail($again)->statut->value);
    }

    public function test_history_is_listed_per_type_and_filterable_by_product(): void
    {
        $p = $this->butcher();
        $id = $this->postJson($this->url($p['store']), $this->cut($p))->json('donnees.id');

        $this->getJson($this->url($p['store'], "?produit_id={$p['filet']->id}"))->assertStatus(200)->assertJsonPath('donnees.0.id', $id);
        $this->getJson("/api/boutiques/{$p['store']->id}/preparations")->assertStatus(200)->assertJsonCount(0, 'donnees');
        // Une découpe n'est pas une préparation.
        $this->getJson("/api/boutiques/{$p['store']->id}/preparations/{$id}")->assertStatus(404);
    }

    public function test_the_feature_gate_store_isolation_and_permissions(): void
    {
        $p = $this->butcher();
        $id = $this->postJson($this->url($p['store']), $this->cut($p))->json('donnees.id');

        // Une épicerie sans la feature `decoupe`.
        ['store' => $grocery] = $this->createStoreWithFeatures(['produits', 'stock', 'ventes']);
        $this->getJson($this->url($grocery))->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');
        // L'opération d'une autre boutique est introuvable.
        $this->getJson("/api/boutiques/{$grocery->id}/decoupes/{$id}")->assertStatus(404);

        Sanctum::actingAs($p['owner']);
        $butcherBoy = User::factory()->create();
        $cashier = User::factory()->create();
        $this->postJson("/api/boutiques/{$p['store']->id}/membres", ['email' => $butcherBoy->email, 'role' => 'employe'])->assertStatus(201);
        $this->postJson("/api/boutiques/{$p['store']->id}/membres", ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);

        // Le caissier consulte, ne découpe pas.
        Sanctum::actingAs($cashier);
        $this->getJson($this->url($p['store'], "/{$id}"))->assertStatus(200);
        $this->postJson($this->url($p['store']), $this->cut($p))->assertStatus(403);

        // Le boucher (employé) découpe, mais n'annule pas.
        Sanctum::actingAs($butcherBoy);
        $this->postJson("/api/boutiques/{$p['store']->id}/stocks/{$p['carcass']->id}/mouvements", ['type' => 'achat', 'quantite' => 100])->assertStatus(403);
        Sanctum::actingAs($p['owner']);
        $this->postJson("/api/boutiques/{$p['store']->id}/stocks/{$p['carcass']->id}/mouvements", ['type' => 'achat', 'quantite' => 100])->assertStatus(201);
        Sanctum::actingAs($butcherBoy);
        $this->postJson($this->url($p['store']), $this->cut($p))->assertStatus(201);
        $this->postJson($this->url($p['store'], "/{$id}/annuler"), ['motif' => 'Non'])->assertStatus(403);
    }
}

<?php

namespace Tests\Feature\Modules\Orders;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Restaurant : carte, suppléments, salle, cuisine, additions — docs/modules.md §Restaurant. */
class RestaurantTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private const FEATURES = ['produits', 'categories', 'stock', 'recettes', 'tables', 'reservations', 'commandes', 'supplements', 'cuisine', 'ventes', 'caisse', 'clients', 'rapports', 'rapports_gestion'];

    /** @return array{owner: User, store: Store, register: CashRegister, poulet: Product, jus: Product, frites: Product, table: DiningTable} */
    private function restaurant(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(self::FEATURES);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);

        $make = fn (string $nom, int $prix, string $type) => Product::factory()->for($store)->create([
            'nom' => $nom, 'vente_detail_active' => true, 'prix_detail' => $prix, 'vente_gros_active' => false, 'type_article' => $type,
        ]);
        $poulet = $make('Poulet braisé', 3500, 'plat');
        $jus = $make('Jus de bissap', 500, 'boisson');
        $frites = $make('Frites', 1000, 'plat');
        foreach ([$poulet, $jus, $frites] as $product) {
            $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 20])->assertStatus(201);
        }
        $tableId = $this->postJson("/api/boutiques/{$store->id}/tables", ['nom' => 'Table 1', 'capacite' => 4])->assertStatus(201)->json('donnees.id');

        return [
            'owner' => $owner, 'store' => $store, 'register' => $register, 'poulet' => $poulet, 'jus' => $jus, 'frites' => $frites,
            'table' => DiningTable::query()->withoutGlobalScopes()->findOrFail($tableId),
        ];
    }

    private function url(Store $store, string $path): string
    {
        return "/api/boutiques/{$store->id}/{$path}";
    }

    // --- Carte ---

    public function test_a_dish_has_a_kind_a_preparation_time_and_paid_supplements(): void
    {
        ['store' => $store, 'poulet' => $poulet, 'frites' => $frites] = $this->restaurant();

        $this->putJson($this->url($store, "produits/{$poulet->id}"), ['temps_preparation_minutes' => 20, 'supplement_ids' => [$frites->id, $poulet->id]])
            ->assertOk()
            ->assertJsonPath('donnees.type_article', 'plat')
            ->assertJsonPath('donnees.temps_preparation_minutes', 20)
            ->assertJsonPath('donnees.disponible', true)
            // Un plat n'est pas son propre supplément.
            ->assertJsonCount(1, 'donnees.supplements')
            ->assertJsonPath('donnees.supplements.0.nom', 'Frites');

        $this->getJson($this->url($store, 'produits?type_article=boisson'))->assertOk()->assertJsonCount(1, 'donnees');
    }

    public function test_supplements_become_lines_attached_to_their_dish(): void
    {
        ['store' => $store, 'poulet' => $poulet, 'jus' => $jus, 'frites' => $frites] = $this->restaurant();
        $poulet->syncSupplements([$frites->id]);

        $order = $this->postJson($this->url($store, 'commandes'), [
            'type' => 'a_emporter',
            'lignes' => [['produit_id' => $poulet->id, 'quantite' => 2, 'supplements' => [['produit_id' => $frites->id]]]],
        ])->assertStatus(201)->assertJsonCount(2, 'donnees.lignes')->assertJsonPath('donnees.total', '9000.00')->json('donnees');

        $this->assertSame($order['lignes'][0]['id'], $order['lignes'][1]['ligne_parent_id']);
        $this->assertSame('2.000', $order['lignes'][1]['quantite']);

        $this->postJson($this->url($store, 'commandes'), [
            'type' => 'a_emporter',
            'lignes' => [['produit_id' => $poulet->id, 'quantite' => 1, 'supplements' => [['produit_id' => $jus->id]]]],
        ])->assertStatus(422)->assertJsonPath('code', 'SUPPLEMENT_INVALIDE');
    }

    public function test_a_sold_out_dish_cannot_be_ordered(): void
    {
        ['store' => $store, 'poulet' => $poulet] = $this->restaurant();
        $this->putJson($this->url($store, "produits/{$poulet->id}"), ['disponible' => false])->assertOk()->assertJsonPath('donnees.disponible', false);

        $this->postJson($this->url($store, 'commandes'), ['type' => 'a_emporter', 'lignes' => [['produit_id' => $poulet->id, 'quantite' => 1]]])
            ->assertStatus(422)->assertJsonPath('code', 'PRODUIT_INDISPONIBLE');
        $this->getJson($this->url($store, 'produits?disponible=1'))->assertOk()->assertJsonCount(2, 'donnees');
    }

    // --- Recettes ---

    public function test_a_recipe_gives_the_cost_and_its_ingredients_leave_stock_at_sale(): void
    {
        ['store' => $store, 'register' => $register] = $this->restaurant();
        $riz = Product::factory()->for($store)->create(['nom' => 'Riz', 'unite' => 'kg', 'prix_achat' => 600, 'type_article' => 'ingredient']);
        $poulet = Product::factory()->for($store)->create(['nom' => 'Poulet cru', 'unite' => 'kg', 'prix_achat' => 3000, 'type_article' => 'ingredient']);
        $plat = Product::factory()->for($store)->create(['nom' => 'Riz au poulet', 'vente_detail_active' => true, 'prix_detail' => 2500, 'vente_gros_active' => false, 'type_article' => 'plat']);
        foreach ([$riz, $poulet] as $ingredient) {
            $this->postJson($this->url($store, "stocks/{$ingredient->id}/mouvements"), ['type' => 'initial', 'quantite' => 10])->assertStatus(201);
        }

        // 2 portions : 0,4 kg de riz (240) + 0,5 kg de poulet (1500) = 1740, soit 870 la portion.
        $this->putJson($this->url($store, "produits/{$plat->id}/recette"), [
            'portions' => 2,
            'ingredients' => [['produit_id' => $riz->id, 'quantite' => 0.4], ['produit_id' => $poulet->id, 'quantite' => 0.5]],
        ])->assertOk()->assertJsonPath('donnees.cout_portion', '870.00')->assertJsonPath('donnees.marge', '1630.00');
        $this->assertSame('870.00', $plat->fresh()->prix_achat);

        $saleId = $this->postJson($this->url($store, 'ventes/encaisser'), [
            'caisse_id' => $register->id, 'lignes' => [['produit_id' => $plat->id, 'mode_prix' => 'detail', 'quantite' => 3]],
        ])->assertStatus(201)->json('donnees.id');
        $this->getJson($this->url($store, "stocks/{$riz->id}"))->assertJsonPath('donnees.quantite', '9.400');
        $this->getJson($this->url($store, "stocks/{$poulet->id}"))->assertJsonPath('donnees.quantite', '9.250');

        // Annulée : les ingrédients reviennent.
        $this->postJson($this->url($store, "ventes/{$saleId}/annuler"), ['motif' => 'Erreur', 'caisse_id' => $register->id])->assertOk();
        $this->getJson($this->url($store, "stocks/{$riz->id}"))->assertJsonPath('donnees.quantite', '10.000');
    }

    // --- Salle ---

    public function test_tables_show_their_status_and_orders_can_move_or_merge(): void
    {
        ['owner' => $owner, 'store' => $store, 'poulet' => $poulet, 'jus' => $jus, 'table' => $table] = $this->restaurant();
        $terrasse = $this->postJson($this->url($store, 'tables'), ['nom' => 'T2', 'espace' => 'Terrasse', 'capacite' => 2])->assertStatus(201)->json('donnees.id');

        $first = $this->postJson($this->url($store, 'commandes'), ['type' => 'sur_place', 'table_id' => $table->id, 'nombre_couverts' => 3, 'lignes' => [['produit_id' => $poulet->id, 'quantite' => 1]]])
            ->assertStatus(201)->assertJsonPath('donnees.serveur.id', $owner->id)->assertJsonPath('donnees.nombre_couverts', 3)->json('donnees.id');
        $this->postJson($this->url($store, "commandes/{$first}/addition"))->assertOk();
        $tables = collect($this->getJson($this->url($store, 'tables'))->json('donnees'))->keyBy('id');
        $this->assertSame('attente_paiement', $tables[$table->id]['statut']);
        $this->assertSame('disponible', $tables[$terrasse]['statut']);
        $this->assertSame('Terrasse', $tables[$terrasse]['espace']);

        // Déplacée : l'ancienne table est à débarrasser.
        $this->putJson($this->url($store, "commandes/{$first}"), ['table_id' => $terrasse])->assertOk();
        $tables = collect($this->getJson($this->url($store, 'tables'))->json('donnees'))->keyBy('id');
        $this->assertSame('a_nettoyer', $tables[$table->id]['statut']);

        // Deux tables réunies : une seule addition.
        $second = $this->postJson($this->url($store, 'commandes'), ['type' => 'sur_place', 'table_id' => $table->id, 'lignes' => [['produit_id' => $jus->id, 'quantite' => 2]]])->json('donnees.id');
        $this->postJson($this->url($store, "commandes/{$first}/regrouper"), ['commande_ids' => [$second]])
            ->assertOk()->assertJsonCount(2, 'donnees.lignes')->assertJsonPath('donnees.total', '4500.00');
        $this->getJson($this->url($store, "commandes/{$second}"))->assertJsonPath('donnees.statut', 'annulee');
    }

    // --- Cuisine ---

    public function test_the_kitchen_moves_each_dish_and_an_unavailable_one_leaves_the_bill(): void
    {
        ['store' => $store, 'poulet' => $poulet, 'jus' => $jus, 'frites' => $frites] = $this->restaurant();
        $order = $this->postJson($this->url($store, 'commandes'), ['type' => 'a_emporter', 'lignes' => [
            ['produit_id' => $poulet->id, 'quantite' => 1, 'note' => 'Sans piment'], ['produit_id' => $frites->id, 'quantite' => 1], ['produit_id' => $jus->id, 'quantite' => 1],
        ]])->json('donnees');
        [$pouletLine, $fritesLine, $jusLine] = array_column($order['lignes'], 'id');

        $this->getJson($this->url($store, 'cuisine'))->assertOk()->assertJsonCount(3, 'donnees.0.lignes')->assertJsonPath('donnees.0.lignes.0.note', 'Sans piment');
        $this->getJson($this->url($store, 'cuisine?boissons=0'))->assertJsonCount(2, 'donnees.0.lignes');

        $this->postJson($this->url($store, "commandes/{$order['id']}/lignes/{$pouletLine}/cuisine"), ['statut' => 'en_preparation'])
            ->assertOk()->assertJsonPath('donnees.statut', 'en_preparation');
        $this->postJson($this->url($store, "commandes/{$order['id']}/lignes/{$fritesLine}/cuisine"), ['statut' => 'indisponible', 'marquer_epuise' => true])
            ->assertOk()->assertJsonPath('donnees.total', '4000.00');
        $this->assertFalse($frites->fresh()->disponible);
        $this->postJson($this->url($store, "commandes/{$order['id']}/lignes/{$pouletLine}/cuisine"), ['statut' => 'prete'])->assertOk();
        $this->postJson($this->url($store, "commandes/{$order['id']}/lignes/{$jusLine}/cuisine"), ['statut' => 'prete'])
            ->assertOk()->assertJsonPath('donnees.statut', 'prete');
        $this->assertNotNull($this->getJson($this->url($store, "commandes/{$order['id']}"))->json('donnees.lignes.0.prete_le'));
    }

    // --- Encaissement ---

    public function test_a_bill_is_split_and_paid_with_several_methods(): void
    {
        ['store' => $store, 'register' => $register, 'poulet' => $poulet, 'jus' => $jus] = $this->restaurant();
        $order = $this->postJson($this->url($store, 'commandes'), ['type' => 'a_emporter', 'lignes' => [
            ['produit_id' => $poulet->id, 'quantite' => 2], ['produit_id' => $jus->id, 'quantite' => 2],
        ]])->json('donnees');
        [$pouletLine, $jusLine] = array_column($order['lignes'], 'id');

        // Première personne : un poulet et un jus, en Mobile Money.
        $this->postJson($this->url($store, "commandes/{$order['id']}/diviser"), [
            'caisse_id' => $register->id, 'mode_paiement' => 'mobile_money',
            'lignes' => [['ligne_id' => $pouletLine, 'quantite' => 1], ['ligne_id' => $jusLine, 'quantite' => 1]],
        ])->assertOk()->assertJsonPath('donnees.statut', 'en_attente')->assertJsonPath('donnees.deja_regle', '4000.00')->assertJsonPath('donnees.reste_a_payer', '4000.00');

        // Le reste en espèces clôt la commande.
        $this->postJson($this->url($store, "commandes/{$order['id']}/encaisser"), ['caisse_id' => $register->id])
            ->assertOk()->assertJsonPath('donnees.statut', 'payee');
        $sales = $this->getJson($this->url($store, 'ventes'))->json('donnees');
        $this->assertCount(2, $sales);
        $this->assertEqualsCanonicalizing(['mobile_money', 'especes'], array_column($sales, 'mode_paiement'));
        $this->assertEqualsCanonicalizing(['4000.00', '4000.00'], array_column($sales, 'total'));
    }

    public function test_an_order_can_be_paid_by_card_but_credit_needs_a_customer(): void
    {
        ['store' => $store, 'register' => $register, 'poulet' => $poulet] = $this->restaurant();
        $id = $this->postJson($this->url($store, 'commandes'), ['type' => 'a_emporter', 'lignes' => [['produit_id' => $poulet->id, 'quantite' => 1]]])->json('donnees.id');

        $this->postJson($this->url($store, "commandes/{$id}/encaisser"), ['caisse_id' => $register->id, 'mode_paiement' => 'credit'])
            ->assertStatus(422)->assertJsonPath('code', 'CLIENT_REQUIS');
        $this->postJson($this->url($store, "commandes/{$id}/encaisser"), ['caisse_id' => $register->id, 'mode_paiement' => 'carte'])->assertOk();
        $this->assertSame('carte', $this->getJson($this->url($store, 'ventes'))->json('donnees.0.mode_paiement'));
    }

    // --- Réservations ---

    public function test_a_reservation_makes_its_table_reserved_and_prevents_double_booking(): void
    {
        ['store' => $store, 'table' => $table] = $this->restaurant();
        $at = now()->addMinutes(40)->toIso8601String();

        $id = $this->postJson($this->url($store, 'reservations'), ['nom_client' => 'Famille Kouassi', 'telephone_client' => '0700000000', 'prevue_le' => $at, 'nombre_personnes' => 4, 'table_id' => $table->id])
            ->assertStatus(201)->assertJsonPath('donnees.statut', 'confirmee')->json('donnees.id');
        $this->postJson($this->url($store, 'reservations'), ['nom_client' => 'Autre', 'prevue_le' => $at, 'nombre_personnes' => 2, 'table_id' => $table->id])
            ->assertStatus(422)->assertJsonPath('code', 'TABLE_DEJA_RESERVEE');

        $this->getJson($this->url($store, 'tables'))->assertJsonPath('donnees.0.statut', 'reservee')->assertJsonPath('donnees.0.reservation.nom_client', 'Famille Kouassi');
        $this->getJson($this->url($store, 'reservations?a_venir=1'))->assertJsonCount(1, 'donnees');
        $this->getJson($this->url($store, 'reservations?date='.now()->addMinutes(40)->toDateString()))->assertOk()->assertJsonCount(1, 'donnees');

        $this->putJson($this->url($store, "reservations/{$id}"), ['statut' => 'non_honoree'])->assertOk();
        $this->getJson($this->url($store, 'tables'))->assertJsonPath('donnees.0.statut', 'disponible');
    }

    // --- Rapport ---

    public function test_the_management_report_has_a_restaurant_section(): void
    {
        ['store' => $store, 'register' => $register, 'poulet' => $poulet, 'jus' => $jus, 'table' => $table] = $this->restaurant();
        $order = $this->postJson($this->url($store, 'commandes'), ['type' => 'sur_place', 'table_id' => $table->id, 'lignes' => [
            ['produit_id' => $poulet->id, 'quantite' => 1], ['produit_id' => $jus->id, 'quantite' => 2],
        ]])->json('donnees');
        $this->postJson($this->url($store, "commandes/{$order['id']}/lignes/{$order['lignes'][0]['id']}/cuisine"), ['statut' => 'prete'])->assertOk();
        $other = $this->postJson($this->url($store, 'commandes'), ['type' => 'a_emporter', 'lignes' => [['produit_id' => $poulet->id, 'quantite' => 1]]])->json('donnees.id');
        $this->postJson($this->url($store, "commandes/{$order['id']}/encaisser"), ['caisse_id' => $register->id])->assertOk();

        $report = $this->getJson($this->url($store, 'rapports/gestion?periode=aujourdhui'))->assertOk()->json('donnees.restaurant');
        $this->assertSame(2, $report['nombre_commandes']);
        $this->assertSame(1, $report['en_attente']);
        $this->assertNotNull($report['temps_moyen_preparation_minutes']);
        $this->assertSame('Jus de bissap', $report['boissons_plus_vendues'][0]['nom']);
        $this->assertSame(['total' => 1, 'occupees' => 0, 'libres' => 1], $report['tables']);
        $this->assertSame(0, $report['reservations_du_jour']);
        $this->assertNotNull($other);
    }
}

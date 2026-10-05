<?php

namespace Tests\Feature\Modules\Orders;

use App\Models\User;
use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Commandes et tables — docs/modules.md §Orders. */
class OrderTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{owner: User, store: Store, register: CashRegister, poulet: Product, jus: Product, table: DiningTable} */
    private function restaurant(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock', 'tables', 'commandes', 'ventes', 'caisse', 'clients']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);

        $poulet = Product::factory()->for($store)->create(['nom' => 'Poulet braisé', 'vente_detail_active' => true, 'prix_detail' => 3500, 'vente_gros_active' => false]);
        $jus = Product::factory()->for($store)->create(['nom' => 'Jus de bissap', 'vente_detail_active' => true, 'prix_detail' => 500, 'vente_gros_active' => false]);
        foreach ([$poulet, $jus] as $product) {
            $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 20])->assertStatus(201);
        }
        $tableId = $this->postJson("/api/boutiques/{$store->id}/tables", ['nom' => 'Table 4', 'capacite' => 4])->assertStatus(201)->json('donnees.id');
        $table = DiningTable::query()->withoutGlobalScopes()->findOrFail($tableId);

        return ['owner' => $owner, 'store' => $store, 'register' => $register, 'poulet' => $poulet, 'jus' => $jus, 'table' => $table];
    }

    private function url(Store $store, string $path = ''): string
    {
        return "/api/boutiques/{$store->id}/commandes{$path}";
    }

    public function test_a_table_order_is_opened_with_lines_and_occupies_the_table(): void
    {
        ['store' => $store, 'poulet' => $poulet, 'jus' => $jus, 'table' => $table] = $this->restaurant();

        $this->postJson($this->url($store), [
            'type' => 'sur_place',
            'table_id' => $table->id,
            'lignes' => [
                ['produit_id' => $poulet->id, 'quantite' => 2, 'note' => 'Bien cuit'],
                ['produit_id' => $jus->id, 'quantite' => 3],
            ],
        ])
            ->assertStatus(201)
            ->assertJsonPath('donnees.statut', 'en_attente')
            ->assertJsonPath('donnees.table.nom', 'Table 4')
            ->assertJsonPath('donnees.lignes.0.note', 'Bien cuit')
            ->assertJsonPath('donnees.lignes.0.prix_unitaire', '3500.00')
            ->assertJsonPath('donnees.total', '8500.00');

        $this->getJson("/api/boutiques/{$store->id}/tables")
            ->assertJsonPath('donnees.0.occupee', true)
            ->assertJsonPath('donnees.0.commande.total', '8500.00');

        // Une seule commande ouverte par table.
        $this->postJson($this->url($store), ['type' => 'sur_place', 'table_id' => $table->id])
            ->assertStatus(422)->assertJsonPath('code', 'TABLE_OCCUPEE');
    }

    public function test_lines_can_be_added_edited_and_removed_while_open(): void
    {
        ['store' => $store, 'poulet' => $poulet, 'jus' => $jus] = $this->restaurant();
        $id = $this->postJson($this->url($store), ['type' => 'a_emporter', 'nom_client' => 'Kofi', 'lignes' => [['produit_id' => $poulet->id, 'quantite' => 1]]])->json('donnees.id');

        $order = $this->postJson($this->url($store, "/{$id}/lignes"), ['lignes' => [['produit_id' => $jus->id, 'quantite' => 2]]])
            ->assertStatus(200)->assertJsonCount(2, 'donnees.lignes')->assertJsonPath('donnees.total', '4500.00')->json('donnees');
        $jusLine = collect($order['lignes'])->firstWhere('produit_id', $jus->id)['id'];

        $this->putJson($this->url($store, "/{$id}/lignes/{$jusLine}"), ['quantite' => 4, 'note' => 'Bien frais'])
            ->assertStatus(200)->assertJsonPath('donnees.total', '5500.00');
        $this->deleteJson($this->url($store, "/{$id}/lignes/{$jusLine}"))
            ->assertStatus(200)->assertJsonCount(1, 'donnees.lignes')->assertJsonPath('donnees.total', '3500.00');
    }

    public function test_checkout_creates_a_real_sale_moves_stock_and_cash_then_frees_the_table(): void
    {
        ['store' => $store, 'register' => $register, 'poulet' => $poulet, 'jus' => $jus, 'table' => $table] = $this->restaurant();
        $id = $this->postJson($this->url($store), ['type' => 'sur_place', 'table_id' => $table->id, 'lignes' => [
            ['produit_id' => $poulet->id, 'quantite' => 2], ['produit_id' => $jus->id, 'quantite' => 3],
        ]])->json('donnees.id');

        foreach (['en_preparation', 'prete', 'servie'] as $status) {
            $this->postJson($this->url($store, "/{$id}/statut"), ['statut' => $status])->assertStatus(200)->assertJsonPath('donnees.statut', $status);
        }

        $order = $this->postJson($this->url($store, "/{$id}/encaisser"), ['caisse_id' => $register->id, 'montant_remise' => 500])
            ->assertStatus(200)->assertJsonPath('donnees.statut', 'payee')->json('donnees');

        $sale = Sale::findOrFail($order['vente_id']);
        $this->assertSame('8000.00', (string) $sale->montant_total);
        $this->assertSame(18.0, (float) Stock::where('produit_id', $poulet->id)->value('quantite'));
        $this->assertSame('8000.00', (string) CashMovement::where('type', 'vente')->value('montant'));
        $this->getJson("/api/boutiques/{$store->id}/tables")->assertJsonPath('donnees.0.occupee', false);

        // Double appui : même vente, rien en double. Puis la commande est figée.
        $this->postJson($this->url($store, "/{$id}/encaisser"), ['caisse_id' => $register->id])->assertStatus(200)->assertJsonPath('donnees.vente_id', $sale->id);
        $this->assertSame(1, Sale::count());
        $this->postJson($this->url($store, "/{$id}/lignes"), ['lignes' => [['produit_id' => $jus->id, 'quantite' => 1]]])
            ->assertStatus(422)->assertJsonPath('code', 'COMMANDE_CLOSE');
    }

    public function test_checkout_errors_roll_back_and_keep_the_order_open(): void
    {
        ['store' => $store, 'register' => $register, 'poulet' => $poulet] = $this->restaurant();
        $empty = $this->postJson($this->url($store), ['type' => 'a_emporter'])->json('donnees.id');
        $this->postJson($this->url($store, "/{$empty}/encaisser"), ['caisse_id' => $register->id])->assertStatus(422)->assertJsonPath('code', 'COMMANDE_VIDE');

        $big = $this->postJson($this->url($store), ['type' => 'a_emporter', 'lignes' => [['produit_id' => $poulet->id, 'quantite' => 50]]])->json('donnees.id');
        $this->postJson($this->url($store, "/{$big}/encaisser"), ['caisse_id' => $register->id])->assertStatus(422)->assertJsonPath('code', 'STOCK_INSUFFISANT');
        $this->getJson($this->url($store, "/{$big}"))->assertJsonPath('donnees.statut', 'en_attente')->assertJsonPath('donnees.vente_id', null);
    }

    public function test_a_dry_cleaner_drop_off_with_services_and_promised_date(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services', 'commandes', 'ventes', 'caisse', 'clients']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);
        $costume = Service::factory()->for($store)->create(['nom' => 'Nettoyage costume', 'prix' => 3000]);

        $id = $this->postJson($this->url($store), [
            'type' => 'depot', 'nom_client' => 'M. Ouédraogo', 'telephone_client' => '70000000', 'date_promise' => '2026-10-12T17:00:00Z',
            'lignes' => [['service_id' => $costume->id, 'quantite' => 2, 'note' => 'Tache sur la manche']],
        ])->assertStatus(201)->assertJsonPath('donnees.type', 'depot')->assertJsonPath('donnees.total', '6000.00')->json('donnees.id');

        $this->getJson($this->url($store, '?ouvertes=1'))->assertJsonCount(1, 'donnees');
        $this->postJson($this->url($store, "/{$id}/encaisser"), ['caisse_id' => $register->id])->assertStatus(200)->assertJsonPath('donnees.statut', 'payee');
        $this->getJson($this->url($store, '?ouvertes=1'))->assertJsonCount(0, 'donnees');
    }

    public function test_a_line_carries_exactly_one_of_product_or_service(): void
    {
        ['store' => $store, 'poulet' => $poulet] = $this->restaurant();

        $this->postJson($this->url($store), ['type' => 'a_emporter', 'lignes' => [['quantite' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('lignes.0', 'erreurs');
        $this->postJson($this->url($store), ['type' => 'a_emporter', 'lignes' => [['produit_id' => $poulet->id, 'mode_prix' => 'gros', 'quantite' => 1]]])
            ->assertStatus(422)->assertJsonPath('code', 'MODE_PRIX_INDISPONIBLE');
    }

    public function test_cancel_frees_the_table_and_freezes_the_order(): void
    {
        ['store' => $store, 'poulet' => $poulet, 'table' => $table] = $this->restaurant();
        $id = $this->postJson($this->url($store), ['type' => 'sur_place', 'table_id' => $table->id, 'lignes' => [['produit_id' => $poulet->id, 'quantite' => 1]]])->json('donnees.id');

        $this->postJson($this->url($store, "/{$id}/annuler"), ['motif' => 'Client parti'])->assertStatus(200)->assertJsonPath('donnees.statut', 'annulee');
        $this->getJson("/api/boutiques/{$store->id}/tables")->assertJsonPath('donnees.0.occupee', false);
        $this->postJson($this->url($store, "/{$id}/statut"), ['statut' => 'prete'])->assertStatus(422)->assertJsonPath('code', 'COMMANDE_CLOSE');
    }

    public function test_waiters_take_orders_but_cannot_cancel_cashout_or_manage_tables(): void
    {
        ['store' => $store, 'poulet' => $poulet, 'register' => $register] = $this->restaurant();
        $waiter = User::factory()->create();
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $waiter->email, 'role' => 'employe'])->assertStatus(201);

        Sanctum::actingAs($waiter);
        $id = $this->postJson($this->url($store), ['type' => 'a_emporter', 'lignes' => [['produit_id' => $poulet->id, 'quantite' => 1]]])->assertStatus(201)->json('donnees.id');
        $this->postJson($this->url($store, "/{$id}/statut"), ['statut' => 'en_preparation'])->assertStatus(200);
        $this->postJson($this->url($store, "/{$id}/annuler"))->assertStatus(403);
        $this->postJson($this->url($store, "/{$id}/encaisser"), ['caisse_id' => $register->id])->assertStatus(403);
        $this->postJson("/api/boutiques/{$store->id}/tables", ['nom' => 'Terrasse 1'])->assertStatus(403);
    }

    public function test_tables_need_the_tables_feature_and_orders_stay_inside_the_store(): void
    {
        ['owner' => $owner, 'store' => $store, 'poulet' => $poulet] = $this->restaurant();
        $this->postJson("/api/boutiques/{$store->id}/tables", ['nom' => 'Terrasse 1', 'capacite' => 6])->assertStatus(201);
        $id = $this->postJson($this->url($store), ['type' => 'a_emporter', 'lignes' => [['produit_id' => $poulet->id, 'quantite' => 1]]])->json('donnees.id');

        ['proprietaire' => $other, 'store' => $pressing] = $this->createStoreWithFeatures(['services', 'commandes', 'ventes']);
        Sanctum::actingAs($other);
        $this->getJson("/api/boutiques/{$pressing->id}/tables")->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');
        $this->getJson("/api/boutiques/{$pressing->id}/commandes/{$id}")->assertStatus(404);
    }
}

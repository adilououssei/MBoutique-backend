<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Transferts de stock entre boutiques — docs/inventory.md §"Transferts". */
class StockTransferTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{owner: User, main: Store, annex: Store, businessId: int, product: Product} */
    private function twoStores(): array
    {
        ['proprietaire' => $owner, 'store' => $main, 'businessId' => $businessId] = $this->createStoreWithFeatures(['produits', 'categories', 'stock']);
        Sanctum::actingAs($owner);
        $annexId = $this->postJson("/api/entreprises/{$businessId}/boutiques", ['nom' => 'Annexe', 'domaine_activite_id' => $main->domaine_activite_id])
            ->assertStatus(201)->json('donnees.id');

        $category = Category::factory()->for($main)->create(['nom' => 'Boissons']);
        $product = Product::factory()->for($main)->create(['nom' => 'Coca 33cl', 'slug' => 'coca-33cl', 'sku' => 'COCA33', 'categorie_id' => $category->id, 'prix_detail' => 500, 'vente_detail_active' => true]);
        $this->postJson("/api/boutiques/{$main->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 50])->assertStatus(201);

        return ['owner' => $owner, 'main' => $main, 'annex' => Store::findOrFail($annexId), 'businessId' => $businessId, 'product' => $product];
    }

    /** Stock d'un produit de $store, lu hors de tout contexte de boutique. */
    private function quantityIn(Store $store, string $slug): ?float
    {
        app(TenantContextContract::class)->clear();
        $productId = Product::withoutStoreScope()->where('boutique_id', $store->id)->where('slug', $slug)->value('id');
        $quantity = Stock::withoutStoreScope()->where('produit_id', $productId)->value('quantite');

        return $quantity === null ? null : (float) $quantity;
    }

    public function test_transfer_moves_stock_and_copies_the_product_into_the_destination(): void
    {
        ['main' => $main, 'annex' => $annex, 'product' => $product] = $this->twoStores();
        $this->postJson("/api/boutiques/{$annex->id}/categories", ['nom' => 'Boissons'])->assertStatus(201);

        $transfer = $this->postJson("/api/boutiques/{$main->id}/transferts", [
            'boutique_destination_id' => $annex->id,
            'lignes' => [['produit_id' => $product->id, 'quantite' => 12]],
            'note' => 'Réassort',
        ])->assertStatus(201)
            ->assertJsonPath('donnees.sens', 'sortant')
            ->assertJsonPath('donnees.destination.nom', 'Annexe')
            ->assertJsonPath('donnees.lignes.0.produit_cree', true)
            ->json('donnees');

        $this->assertSame(38.0, $this->quantityIn($main, 'coca-33cl'));
        $this->assertSame(12.0, $this->quantityIn($annex, 'coca-33cl'));

        $copy = Product::withoutStoreScope()->where('boutique_id', $annex->id)->where('slug', 'coca-33cl')->firstOrFail();
        $this->assertSame('COCA33', $copy->sku);
        $this->assertSame('500.00', (string) $copy->prix_detail);
        $this->assertSame(Category::withoutStoreScope()->where('boutique_id', $annex->id)->value('id'), $copy->categorie_id);
        $this->assertSame(2, StockMovement::withoutStoreScope()->where('reference_type', 'transfert_stock')->where('reference_id', $transfer['id'])->count());

        // La destination voit le même transfert comme entrant, avec son propre produit.
        $this->getJson("/api/boutiques/{$annex->id}/transferts/{$transfer['id']}")
            ->assertStatus(200)
            ->assertJsonPath('donnees.sens', 'entrant')
            ->assertJsonPath('donnees.lignes.0.produit_id', $copy->id);
    }

    public function test_a_second_transfer_reuses_the_matching_product(): void
    {
        ['main' => $main, 'annex' => $annex, 'product' => $product] = $this->twoStores();
        $url = "/api/boutiques/{$main->id}/transferts";
        $payload = ['boutique_destination_id' => $annex->id, 'lignes' => [['produit_id' => $product->id, 'quantite' => 5]]];

        $this->postJson($url, $payload)->assertStatus(201);
        $this->postJson($url, $payload)->assertStatus(201)->assertJsonPath('donnees.lignes.0.produit_cree', false);

        $this->assertSame(1, Product::withoutStoreScope()->where('boutique_id', $annex->id)->count());
        $this->assertSame(10.0, $this->quantityIn($annex, 'coca-33cl'));
        $this->assertSame(40.0, $this->quantityIn($main, 'coca-33cl'));
    }

    public function test_insufficient_stock_rolls_everything_back(): void
    {
        ['main' => $main, 'annex' => $annex, 'product' => $product] = $this->twoStores();

        $this->postJson("/api/boutiques/{$main->id}/transferts", [
            'boutique_destination_id' => $annex->id,
            'lignes' => [['produit_id' => $product->id, 'quantite' => 80]],
        ])->assertStatus(422)->assertJsonPath('code', 'STOCK_INSUFFISANT');

        $this->assertSame(50.0, $this->quantityIn($main, 'coca-33cl'));
        $this->assertSame(0, Product::withoutStoreScope()->where('boutique_id', $annex->id)->count());
        $this->assertDatabaseCount('transferts_stock', 0);
    }

    public function test_destination_must_be_another_store_of_the_same_business(): void
    {
        ['main' => $main, 'product' => $product] = $this->twoStores();
        ['store' => $foreign] = $this->createStoreWithFeatures(['produits', 'stock']);
        Sanctum::actingAs(Store::findOrFail($main->id)->storeUsers()->first()->user);

        foreach ([$main->id, $foreign->id, 999999] as $destinationId) {
            $this->postJson("/api/boutiques/{$main->id}/transferts", [
                'boutique_destination_id' => $destinationId,
                'lignes' => [['produit_id' => $product->id, 'quantite' => 1]],
            ])->assertStatus(422)->assertJsonPath('code', 'TRANSFERT_INVALIDE');
        }
    }

    public function test_destinations_lists_only_stores_where_the_user_can_add_stock(): void
    {
        ['owner' => $owner, 'main' => $main, 'annex' => $annex, 'product' => $product] = $this->twoStores();

        $this->getJson("/api/boutiques/{$main->id}/transferts/destinations")
            ->assertStatus(200)
            ->assertJsonCount(1, 'donnees')
            ->assertJsonPath('donnees.0.id', $annex->id);

        // Un gérant de la boutique principale, simple employé de l'annexe : pas de transfert vers l'annexe.
        $manager = User::factory()->create();
        $this->postJson("/api/boutiques/{$main->id}/membres", ['email' => $manager->email, 'role' => 'gerant'])->assertStatus(201);
        $this->postJson("/api/boutiques/{$annex->id}/membres", ['email' => $manager->email, 'role' => 'employe'])->assertStatus(201);

        Sanctum::actingAs($manager);
        $this->getJson("/api/boutiques/{$main->id}/transferts/destinations")->assertJsonCount(0, 'donnees');
        $this->postJson("/api/boutiques/{$main->id}/transferts", [
            'boutique_destination_id' => $annex->id,
            'lignes' => [['produit_id' => $product->id, 'quantite' => 1]],
        ])->assertStatus(422)->assertJsonPath('code', 'TRANSFERT_INVALIDE');
    }

    public function test_listing_is_split_by_direction_and_hidden_from_other_stores(): void
    {
        ['main' => $main, 'annex' => $annex, 'product' => $product] = $this->twoStores();
        $id = $this->postJson("/api/boutiques/{$main->id}/transferts", [
            'boutique_destination_id' => $annex->id,
            'lignes' => [['produit_id' => $product->id, 'quantite' => 2]],
        ])->json('donnees.id');

        $this->getJson("/api/boutiques/{$main->id}/transferts?sens=sortant")->assertJsonCount(1, 'donnees');
        $this->getJson("/api/boutiques/{$main->id}/transferts?sens=entrant")->assertJsonCount(0, 'donnees');
        $this->getJson("/api/boutiques/{$annex->id}/transferts?sens=entrant")->assertJsonCount(1, 'donnees');

        ['store' => $other] = $this->createStoreWithFeatures(['produits', 'stock']);
        $this->getJson("/api/boutiques/{$other->id}/transferts/{$id}")->assertStatus(404);
    }
}

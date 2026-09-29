<?php

namespace Tests\Feature\Modules\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    public function test_owner_can_create_a_product(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/boutiques/{$store->id}/produits", [
            'nom' => 'Coca-Cola 50cl',
            'vente_detail_active' => true,
            'prix_detail' => 500,
            'unite' => 'piece',
        ]);

        $response->assertStatus(201)->assertJsonPath('donnees.nom', 'Coca-Cola 50cl');
        $this->assertDatabaseHas('produits', ['boutique_id' => $store->id, 'nom' => 'Coca-Cola 50cl']);
    }

    public function test_prices_are_stored_as_exact_decimals_not_rounded_floats(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $id = $this->postJson("/api/boutiques/{$store->id}/produits", [
            'nom' => 'Article', 'vente_detail_active' => true, 'prix_detail' => 1999.99,
        ])->json('donnees.id');

        $this->assertSame('1999.99', Product::find($id)->prix_detail);
    }

    public function test_owner_can_list_read_update_and_delete_a_product(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/produits")->assertStatus(200)->assertJsonCount(1, 'donnees');
        $this->getJson("/api/boutiques/{$store->id}/produits/{$product->id}")->assertStatus(200);

        $this->putJson("/api/boutiques/{$store->id}/produits/{$product->id}", ['nom' => 'Renamed'])
            ->assertStatus(200)->assertJsonPath('donnees.nom', 'Renamed');

        $this->deleteJson("/api/boutiques/{$store->id}/produits/{$product->id}")->assertStatus(200);
        $this->assertSoftDeleted('produits', ['id' => $product->id]);
    }

    public function test_name_is_required(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/produits", ['vente_detail_active' => true, 'prix_detail' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nom', 'erreurs');
    }

    public function test_a_category_belonging_to_another_store_is_rejected(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['produits', 'categories']);
        $categoryOfStoreA = Category::factory()->for($storeA)->create();

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($ownerB);

        $response = $this->postJson("/api/boutiques/{$storeB->id}/produits", [
            'nom' => 'Article',
            'vente_detail_active' => true,
            'prix_detail' => 100,
            'categorie_id' => $categoryOfStoreA->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('categorie_id', 'erreurs');
    }

    public function test_a_category_belonging_to_the_same_store_is_accepted(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'categories']);
        $category = Category::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/boutiques/{$store->id}/produits", [
            'nom' => 'Article', 'vente_detail_active' => true, 'prix_detail' => 100, 'categorie_id' => $category->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('donnees.categorie.id', $category->id);
    }

    public function test_sku_is_unique_per_store_not_globally(): void
    {
        ['proprietaire' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/boutiques/{$storeA->id}/produits", ['nom' => 'A', 'vente_detail_active' => true, 'prix_detail' => 1, 'sku' => 'SKU-1'])
            ->assertStatus(201);

        $this->postJson("/api/boutiques/{$storeA->id}/produits", ['nom' => 'B', 'vente_detail_active' => true, 'prix_detail' => 1, 'sku' => 'SKU-1'])
            ->assertStatus(422)->assertJsonValidationErrors('sku', 'erreurs');

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($ownerB);
        $this->postJson("/api/boutiques/{$storeB->id}/produits", ['nom' => 'C', 'vente_detail_active' => true, 'prix_detail' => 1, 'sku' => 'SKU-1'])
            ->assertStatus(201);
    }

    public function test_barcode_is_unique_per_store_not_globally(): void
    {
        ['proprietaire' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/boutiques/{$storeA->id}/produits", ['nom' => 'A', 'vente_detail_active' => true, 'prix_detail' => 1, 'code_barres' => '12345'])
            ->assertStatus(201);

        $this->postJson("/api/boutiques/{$storeA->id}/produits", ['nom' => 'B', 'vente_detail_active' => true, 'prix_detail' => 1, 'code_barres' => '12345'])
            ->assertStatus(422)->assertJsonValidationErrors('code_barres', 'erreurs');
    }

    public function test_a_member_of_another_store_cannot_reach_this_products_store(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['produits']);
        $product = Product::factory()->for($storeA)->create();

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/boutiques/{$storeB->id}/produits/{$product->id}")->assertStatus(404);
        $this->putJson("/api/boutiques/{$storeB->id}/produits/{$product->id}", ['nom' => 'x'])->assertStatus(404);
        $this->deleteJson("/api/boutiques/{$storeB->id}/produits/{$product->id}")->assertStatus(404);
    }

    // --- Retail / Wholesale pricing (docs/catalog.md §5) ---

    public function test_retail_price_is_required_when_retail_is_enabled(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/produits", ['nom' => 'Article', 'vente_detail_active' => true])
            ->assertStatus(422)->assertJsonValidationErrors('prix_detail', 'erreurs');
    }

    public function test_wholesale_price_is_required_when_wholesale_is_enabled(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/produits", ['nom' => 'Article', 'vente_gros_active' => true])
            ->assertStatus(422)->assertJsonValidationErrors('prix_gros', 'erreurs');
    }

    public function test_retail_price_is_rejected_when_retail_is_not_enabled(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/produits", [
            'nom' => 'Article', 'vente_detail_active' => false, 'prix_detail' => 500,
        ])->assertStatus(422)->assertJsonValidationErrors('prix_detail', 'erreurs');
    }

    public function test_wholesale_price_is_rejected_when_wholesale_is_not_enabled(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/produits", [
            'nom' => 'Article', 'vente_gros_active' => false, 'prix_gros' => 450,
        ])->assertStatus(422)->assertJsonValidationErrors('prix_gros', 'erreurs');
    }

    public function test_a_product_can_be_retail_and_wholesale_at_once_with_distinct_prices(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/boutiques/{$store->id}/produits", [
            'nom' => 'Coca-Cola 50cl',
            'prix_achat' => 300,
            'vente_detail_active' => true, 'prix_detail' => 500,
            'vente_gros_active' => true, 'prix_gros' => 450,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.prix_detail', '500.00')
            ->assertJsonPath('donnees.prix_gros', '450.00');
    }

    public function test_disabling_retail_on_update_nulls_out_the_stored_retail_price(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        $product = Product::factory()->retailAndWholesale()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->putJson("/api/boutiques/{$store->id}/produits/{$product->id}", ['vente_detail_active' => false])
            ->assertStatus(200);

        $this->assertNull($product->fresh()->prix_detail);
        $this->assertNotNull($product->fresh()->prix_gros);
    }

    public function test_selling_mode_filter_returns_only_matching_products(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Product::factory()->for($store)->create(); // retail only (factory default)
        Product::factory()->wholesaleOnly()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/produits?mode_prix=gros")
            ->assertStatus(200)->assertJsonCount(1, 'donnees');

        $this->getJson("/api/boutiques/{$store->id}/produits?mode_prix=detail")
            ->assertStatus(200)->assertJsonCount(1, 'donnees');
    }
}

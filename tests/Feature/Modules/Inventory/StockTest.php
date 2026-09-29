<?php

namespace Tests\Feature\Modules\Inventory;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Inventory\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class StockTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    public function test_a_product_with_no_stock_recorded_yet_reads_as_zero_not_404(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/boutiques/{$store->id}/stocks/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('donnees.quantite', '0.000')
            ->assertJsonPath('donnees.quantite_minimum', null)
            ->assertJsonPath('donnees.stock_faible', false)
            ->assertJsonPath('donnees.produit.id', $product->id);

        $this->assertDatabaseMissing('stocks', ['produit_id' => $product->id]);
    }

    public function test_current_stock_reflects_movements(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 100, 'quantite_minimum' => 10]);

        $response = $this->getJson("/api/boutiques/{$store->id}/stocks/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('donnees.quantite', '100.000')
            ->assertJsonPath('donnees.quantite_minimum', '10.000')
            ->assertJsonPath('donnees.stock_faible', false);
    }

    public function test_is_low_stock_is_true_when_quantity_is_at_or_below_the_minimum(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 10, 'quantite_minimum' => 10]);

        $this->getJson("/api/boutiques/{$store->id}/stocks/{$product->id}")->assertJsonPath('donnees.stock_faible', true);
    }

    public function test_owner_can_update_the_minimum_quantity_threshold(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 100]);

        $response = $this->putJson("/api/boutiques/{$store->id}/stocks/{$product->id}", ['quantite_minimum' => 25]);

        $response->assertStatus(200)->assertJsonPath('donnees.quantite_minimum', '25.000');
        $this->assertSame('100.000', Stock::where('produit_id', $product->id)->first()->quantite);
    }

    public function test_the_threshold_cannot_be_set_before_stock_is_initialized(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->putJson("/api/boutiques/{$store->id}/stocks/{$product->id}", ['quantite_minimum' => 25])
            ->assertStatus(404)->assertJsonPath('code', 'STOCK_NON_INITIALISE');
    }

    public function test_updating_the_threshold_never_changes_the_quantity(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 100]);

        $this->putJson("/api/boutiques/{$store->id}/stocks/{$product->id}", ['quantite' => 999999, 'quantite_minimum' => 5])
            ->assertStatus(200);

        $this->assertSame('100.000', Stock::where('produit_id', $product->id)->first()->quantite);
    }

    // --- List / search / pagination / low_stock filter ---

    public function test_list_only_includes_products_with_a_recorded_stock(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $withStock = Product::factory()->for($store)->create(['nom' => 'Riz 25kg']);
        Product::factory()->for($store)->create(['nom' => 'Sans stock encore']);
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$withStock->id}/mouvements", ['type' => 'initial', 'quantite' => 100]);

        $response = $this->getJson("/api/boutiques/{$store->id}/stocks");

        $response->assertStatus(200)->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.produit.id', $withStock->id);
    }

    public function test_search_matches_the_product_name(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $rice = Product::factory()->for($store)->create(['nom' => 'Riz 25kg']);
        $oil = Product::factory()->for($store)->create(['nom' => 'Huile 5L']);
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$rice->id}/mouvements", ['type' => 'initial', 'quantite' => 100]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$oil->id}/mouvements", ['type' => 'initial', 'quantite' => 50]);

        $this->getJson("/api/boutiques/{$store->id}/stocks?recherche=riz")
            ->assertStatus(200)->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.produit.id', $rice->id);
    }

    public function test_low_stock_filter_returns_only_products_at_or_below_their_minimum(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $low = Product::factory()->for($store)->create();
        $healthy = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$low->id}/mouvements", ['type' => 'initial', 'quantite' => 5, 'quantite_minimum' => 10]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$healthy->id}/mouvements", ['type' => 'initial', 'quantite' => 50, 'quantite_minimum' => 10]);

        $response = $this->getJson("/api/boutiques/{$store->id}/stocks?stock_faible=true");

        $response->assertStatus(200)->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.produit.id', $low->id);
    }

    public function test_list_is_paginated(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $products = Product::factory()->for($store)->count(15)->create();
        Sanctum::actingAs($owner);
        foreach ($products as $product) {
            $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 10]);
        }

        $response = $this->getJson("/api/boutiques/{$store->id}/stocks?par_page=10");

        $response->assertStatus(200)->assertJsonCount(10, 'donnees')->assertJsonPath('meta.total', 15);
    }

    // --- Isolation ---

    public function test_a_member_of_another_store_cannot_read_this_products_stock(): void
    {
        ['proprietaire' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['produits', 'stock']);
        $productA = Product::factory()->for($storeA)->create();
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/boutiques/{$storeA->id}/stocks/{$productA->id}/mouvements", ['type' => 'initial', 'quantite' => 100]);

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['produits', 'stock']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/boutiques/{$storeB->id}/stocks/{$productA->id}")->assertStatus(404);
    }

    // --- Services excluded ---

    public function test_a_service_cannot_be_used_as_an_inventory_product(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'services', 'stock']);
        $service = Service::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        // {product} is scoped through Store::products(), which a Service
        // id simply never matches — same 404 as a cross-store product.
        $this->getJson("/api/boutiques/{$store->id}/stocks/{$service->id}")->assertStatus(404);
    }
}

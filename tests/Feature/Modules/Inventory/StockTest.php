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
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/stores/{$store->id}/inventory/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.quantity', '0.000')
            ->assertJsonPath('data.minimum_quantity', null)
            ->assertJsonPath('data.is_low_stock', false)
            ->assertJsonPath('data.product.id', $product->id);

        $this->assertDatabaseMissing('stocks', ['product_id' => $product->id]);
    }

    public function test_current_stock_reflects_movements(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 100, 'minimum_quantity' => 10]);

        $response = $this->getJson("/api/stores/{$store->id}/inventory/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.quantity', '100.000')
            ->assertJsonPath('data.minimum_quantity', '10.000')
            ->assertJsonPath('data.is_low_stock', false);
    }

    public function test_is_low_stock_is_true_when_quantity_is_at_or_below_the_minimum(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 10, 'minimum_quantity' => 10]);

        $this->getJson("/api/stores/{$store->id}/inventory/{$product->id}")->assertJsonPath('data.is_low_stock', true);
    }

    public function test_owner_can_update_the_minimum_quantity_threshold(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 100]);

        $response = $this->putJson("/api/stores/{$store->id}/inventory/{$product->id}", ['minimum_quantity' => 25]);

        $response->assertStatus(200)->assertJsonPath('data.minimum_quantity', '25.000');
        $this->assertSame('100.000', Stock::where('product_id', $product->id)->first()->quantity);
    }

    public function test_the_threshold_cannot_be_set_before_stock_is_initialized(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->putJson("/api/stores/{$store->id}/inventory/{$product->id}", ['minimum_quantity' => 25])
            ->assertStatus(404)->assertJsonPath('code', 'STOCK_NOT_INITIALIZED');
    }

    public function test_updating_the_threshold_never_changes_the_quantity(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 100]);

        $this->putJson("/api/stores/{$store->id}/inventory/{$product->id}", ['quantity' => 999999, 'minimum_quantity' => 5])
            ->assertStatus(200);

        $this->assertSame('100.000', Stock::where('product_id', $product->id)->first()->quantity);
    }

    // --- List / search / pagination / low_stock filter ---

    public function test_list_only_includes_products_with_a_recorded_stock(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $withStock = Product::factory()->for($store)->create(['name' => 'Riz 25kg']);
        Product::factory()->for($store)->create(['name' => 'Sans stock encore']);
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/inventory/{$withStock->id}/movements", ['type' => 'initial', 'quantity' => 100]);

        $response = $this->getJson("/api/stores/{$store->id}/inventory");

        $response->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.product.id', $withStock->id);
    }

    public function test_search_matches_the_product_name(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $rice = Product::factory()->for($store)->create(['name' => 'Riz 25kg']);
        $oil = Product::factory()->for($store)->create(['name' => 'Huile 5L']);
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/inventory/{$rice->id}/movements", ['type' => 'initial', 'quantity' => 100]);
        $this->postJson("/api/stores/{$store->id}/inventory/{$oil->id}/movements", ['type' => 'initial', 'quantity' => 50]);

        $this->getJson("/api/stores/{$store->id}/inventory?search=riz")
            ->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.product.id', $rice->id);
    }

    public function test_low_stock_filter_returns_only_products_at_or_below_their_minimum(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $low = Product::factory()->for($store)->create();
        $healthy = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/inventory/{$low->id}/movements", ['type' => 'initial', 'quantity' => 5, 'minimum_quantity' => 10]);
        $this->postJson("/api/stores/{$store->id}/inventory/{$healthy->id}/movements", ['type' => 'initial', 'quantity' => 50, 'minimum_quantity' => 10]);

        $response = $this->getJson("/api/stores/{$store->id}/inventory?low_stock=true");

        $response->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.product.id', $low->id);
    }

    public function test_list_is_paginated(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $products = Product::factory()->for($store)->count(15)->create();
        Sanctum::actingAs($owner);
        foreach ($products as $product) {
            $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 10]);
        }

        $response = $this->getJson("/api/stores/{$store->id}/inventory?per_page=10");

        $response->assertStatus(200)->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 15);
    }

    // --- Isolation ---

    public function test_a_member_of_another_store_cannot_read_this_products_stock(): void
    {
        ['owner' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['products', 'inventory']);
        $productA = Product::factory()->for($storeA)->create();
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/stores/{$storeA->id}/inventory/{$productA->id}/movements", ['type' => 'initial', 'quantity' => 100]);

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['products', 'inventory']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/stores/{$storeB->id}/inventory/{$productA->id}")->assertStatus(404);
    }

    // --- Services excluded ---

    public function test_a_service_cannot_be_used_as_an_inventory_product(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'services', 'inventory']);
        $service = Service::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        // {product} is scoped through Store::products(), which a Service
        // id simply never matches — same 404 as a cross-store product.
        $this->getJson("/api/stores/{$store->id}/inventory/{$service->id}")->assertStatus(404);
    }
}

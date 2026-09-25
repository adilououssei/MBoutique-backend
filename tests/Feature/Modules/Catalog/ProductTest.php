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
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/stores/{$store->id}/products", [
            'name' => 'Coca-Cola 50cl',
            'retail_enabled' => true,
            'retail_price' => 500,
            'unit' => 'piece',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Coca-Cola 50cl');
        $this->assertDatabaseHas('products', ['store_id' => $store->id, 'name' => 'Coca-Cola 50cl']);
    }

    public function test_prices_are_stored_as_exact_decimals_not_rounded_floats(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $id = $this->postJson("/api/stores/{$store->id}/products", [
            'name' => 'Article', 'retail_enabled' => true, 'retail_price' => 1999.99,
        ])->json('data.id');

        $this->assertSame('1999.99', Product::find($id)->retail_price);
    }

    public function test_owner_can_list_read_update_and_delete_a_product(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/stores/{$store->id}/products")->assertStatus(200)->assertJsonCount(1, 'data');
        $this->getJson("/api/stores/{$store->id}/products/{$product->id}")->assertStatus(200);

        $this->putJson("/api/stores/{$store->id}/products/{$product->id}", ['name' => 'Renamed'])
            ->assertStatus(200)->assertJsonPath('data.name', 'Renamed');

        $this->deleteJson("/api/stores/{$store->id}/products/{$product->id}")->assertStatus(200);
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_name_is_required(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/products", ['retail_enabled' => true, 'retail_price' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_a_category_belonging_to_another_store_is_rejected(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['products', 'categories']);
        $categoryOfStoreA = Category::factory()->for($storeA)->create();

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($ownerB);

        $response = $this->postJson("/api/stores/{$storeB->id}/products", [
            'name' => 'Article',
            'retail_enabled' => true,
            'retail_price' => 100,
            'category_id' => $categoryOfStoreA->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('category_id');
    }

    public function test_a_category_belonging_to_the_same_store_is_accepted(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'categories']);
        $category = Category::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/stores/{$store->id}/products", [
            'name' => 'Article', 'retail_enabled' => true, 'retail_price' => 100, 'category_id' => $category->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.category.id', $category->id);
    }

    public function test_sku_is_unique_per_store_not_globally(): void
    {
        ['owner' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/stores/{$storeA->id}/products", ['name' => 'A', 'retail_enabled' => true, 'retail_price' => 1, 'sku' => 'SKU-1'])
            ->assertStatus(201);

        $this->postJson("/api/stores/{$storeA->id}/products", ['name' => 'B', 'retail_enabled' => true, 'retail_price' => 1, 'sku' => 'SKU-1'])
            ->assertStatus(422)->assertJsonValidationErrors('sku');

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($ownerB);
        $this->postJson("/api/stores/{$storeB->id}/products", ['name' => 'C', 'retail_enabled' => true, 'retail_price' => 1, 'sku' => 'SKU-1'])
            ->assertStatus(201);
    }

    public function test_barcode_is_unique_per_store_not_globally(): void
    {
        ['owner' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/stores/{$storeA->id}/products", ['name' => 'A', 'retail_enabled' => true, 'retail_price' => 1, 'barcode' => '12345'])
            ->assertStatus(201);

        $this->postJson("/api/stores/{$storeA->id}/products", ['name' => 'B', 'retail_enabled' => true, 'retail_price' => 1, 'barcode' => '12345'])
            ->assertStatus(422)->assertJsonValidationErrors('barcode');
    }

    public function test_a_member_of_another_store_cannot_reach_this_products_store(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['products']);
        $product = Product::factory()->for($storeA)->create();

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/stores/{$storeB->id}/products/{$product->id}")->assertStatus(404);
        $this->putJson("/api/stores/{$storeB->id}/products/{$product->id}", ['name' => 'x'])->assertStatus(404);
        $this->deleteJson("/api/stores/{$storeB->id}/products/{$product->id}")->assertStatus(404);
    }

    // --- Retail / Wholesale pricing (docs/catalog.md §5) ---

    public function test_retail_price_is_required_when_retail_is_enabled(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/products", ['name' => 'Article', 'retail_enabled' => true])
            ->assertStatus(422)->assertJsonValidationErrors('retail_price');
    }

    public function test_wholesale_price_is_required_when_wholesale_is_enabled(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/products", ['name' => 'Article', 'wholesale_enabled' => true])
            ->assertStatus(422)->assertJsonValidationErrors('wholesale_price');
    }

    public function test_retail_price_is_rejected_when_retail_is_not_enabled(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/products", [
            'name' => 'Article', 'retail_enabled' => false, 'retail_price' => 500,
        ])->assertStatus(422)->assertJsonValidationErrors('retail_price');
    }

    public function test_wholesale_price_is_rejected_when_wholesale_is_not_enabled(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/products", [
            'name' => 'Article', 'wholesale_enabled' => false, 'wholesale_price' => 450,
        ])->assertStatus(422)->assertJsonValidationErrors('wholesale_price');
    }

    public function test_a_product_can_be_retail_and_wholesale_at_once_with_distinct_prices(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/stores/{$store->id}/products", [
            'name' => 'Coca-Cola 50cl',
            'purchase_price' => 300,
            'retail_enabled' => true, 'retail_price' => 500,
            'wholesale_enabled' => true, 'wholesale_price' => 450,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.retail_price', '500.00')
            ->assertJsonPath('data.wholesale_price', '450.00');
    }

    public function test_disabling_retail_on_update_nulls_out_the_stored_retail_price(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        $product = Product::factory()->retailAndWholesale()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->putJson("/api/stores/{$store->id}/products/{$product->id}", ['retail_enabled' => false])
            ->assertStatus(200);

        $this->assertNull($product->fresh()->retail_price);
        $this->assertNotNull($product->fresh()->wholesale_price);
    }

    public function test_selling_mode_filter_returns_only_matching_products(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Product::factory()->for($store)->create(); // retail only (factory default)
        Product::factory()->wholesaleOnly()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/stores/{$store->id}/products?selling_mode=wholesale")
            ->assertStatus(200)->assertJsonCount(1, 'data');

        $this->getJson("/api/stores/{$store->id}/products?selling_mode=retail")
            ->assertStatus(200)->assertJsonCount(1, 'data');
    }
}

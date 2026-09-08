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
            'selling_price' => 500,
            'unit' => 'piece',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Coca-Cola 50cl');
        $this->assertDatabaseHas('products', ['store_id' => $store->id, 'name' => 'Coca-Cola 50cl']);
    }

    public function test_selling_price_is_stored_as_an_exact_decimal_not_a_rounded_float(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $id = $this->postJson("/api/stores/{$store->id}/products", [
            'name' => 'Article', 'selling_price' => 1999.99,
        ])->json('data.id');

        $this->assertSame('1999.99', Product::find($id)->selling_price);
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

    public function test_selling_price_is_required(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/products", ['name' => 'Article'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('selling_price');
    }

    public function test_a_category_belonging_to_another_store_is_rejected(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['products', 'categories']);
        $categoryOfStoreA = Category::factory()->for($storeA)->create();

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($ownerB);

        $response = $this->postJson("/api/stores/{$storeB->id}/products", [
            'name' => 'Article',
            'selling_price' => 100,
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
            'name' => 'Article', 'selling_price' => 100, 'category_id' => $category->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.category.id', $category->id);
    }

    public function test_sku_is_unique_per_store_not_globally(): void
    {
        ['owner' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/stores/{$storeA->id}/products", ['name' => 'A', 'selling_price' => 1, 'sku' => 'SKU-1'])
            ->assertStatus(201);

        $this->postJson("/api/stores/{$storeA->id}/products", ['name' => 'B', 'selling_price' => 1, 'sku' => 'SKU-1'])
            ->assertStatus(422)->assertJsonValidationErrors('sku');

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($ownerB);
        $this->postJson("/api/stores/{$storeB->id}/products", ['name' => 'C', 'selling_price' => 1, 'sku' => 'SKU-1'])
            ->assertStatus(201);
    }

    public function test_barcode_is_unique_per_store_not_globally(): void
    {
        ['owner' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/stores/{$storeA->id}/products", ['name' => 'A', 'selling_price' => 1, 'barcode' => '12345'])
            ->assertStatus(201);

        $this->postJson("/api/stores/{$storeA->id}/products", ['name' => 'B', 'selling_price' => 1, 'barcode' => '12345'])
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
}

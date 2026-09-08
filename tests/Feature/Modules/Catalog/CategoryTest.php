<?php

namespace Tests\Feature\Modules\Catalog;

use App\Modules\Catalog\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    public function test_owner_can_create_a_category(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['categories']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/stores/{$store->id}/categories", ['name' => 'Boissons']);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Boissons')
            ->assertJsonPath('data.slug', 'boissons');

        $this->assertDatabaseHas('categories', ['store_id' => $store->id, 'slug' => 'boissons']);
    }

    public function test_owner_can_list_categories(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['categories']);
        Category::factory()->for($store)->count(3)->create();
        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/stores/{$store->id}/categories");

        $response->assertStatus(200)->assertJsonCount(3, 'data');
    }

    public function test_owner_can_read_a_single_category(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['categories']);
        $category = Category::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/stores/{$store->id}/categories/{$category->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $category->id);
    }

    public function test_owner_can_update_a_category(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['categories']);
        $category = Category::factory()->for($store)->create(['name' => 'Old']);
        Sanctum::actingAs($owner);

        $response = $this->putJson("/api/stores/{$store->id}/categories/{$category->id}", ['name' => 'New']);

        $response->assertStatus(200)->assertJsonPath('data.name', 'New');
    }

    public function test_owner_can_delete_a_category(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['categories']);
        $category = Category::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/stores/{$store->id}/categories/{$category->id}")->assertStatus(200);

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    public function test_the_same_slug_is_valid_in_two_different_stores(): void
    {
        ['owner' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['categories']);
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/stores/{$storeA->id}/categories", ['name' => 'Boissons'])->assertStatus(201);

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['categories']);
        Sanctum::actingAs($ownerB);
        $this->postJson("/api/stores/{$storeB->id}/categories", ['name' => 'Boissons'])->assertStatus(201);

        $this->assertDatabaseCount('categories', 2);
    }

    public function test_the_same_slug_is_rejected_twice_in_the_same_store(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['categories']);
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/categories", ['name' => 'Boissons'])->assertStatus(201);

        $response = $this->postJson("/api/stores/{$store->id}/categories", ['name' => 'Boissons']);

        $response->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_name_is_required(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['categories']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/categories", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_a_member_of_another_store_cannot_read_this_categorys_store(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['categories']);
        $category = Category::factory()->for($storeA)->create();

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['categories']);
        Sanctum::actingAs($ownerB);

        // storeB in the URL, but the category id belongs to storeA — must
        // 404 via scoped route model binding, never resolve.
        $this->getJson("/api/stores/{$storeB->id}/categories/{$category->id}")->assertStatus(404);
    }
}

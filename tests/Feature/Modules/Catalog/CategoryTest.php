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
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['categories']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/boutiques/{$store->id}/categories", ['nom' => 'Boissons']);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.nom', 'Boissons')
            ->assertJsonPath('donnees.slug', 'boissons');

        $this->assertDatabaseHas('categories', ['boutique_id' => $store->id, 'slug' => 'boissons']);
    }

    public function test_owner_can_list_categories(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['categories']);
        Category::factory()->for($store)->count(3)->create();
        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/boutiques/{$store->id}/categories");

        $response->assertStatus(200)->assertJsonCount(3, 'donnees');
    }

    public function test_owner_can_read_a_single_category(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['categories']);
        $category = Category::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/categories/{$category->id}")
            ->assertStatus(200)
            ->assertJsonPath('donnees.id', $category->id);
    }

    public function test_owner_can_update_a_category(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['categories']);
        $category = Category::factory()->for($store)->create(['nom' => 'Old']);
        Sanctum::actingAs($owner);

        $response = $this->putJson("/api/boutiques/{$store->id}/categories/{$category->id}", ['nom' => 'New']);

        $response->assertStatus(200)->assertJsonPath('donnees.nom', 'New');
    }

    public function test_owner_can_delete_a_category(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['categories']);
        $category = Category::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/boutiques/{$store->id}/categories/{$category->id}")->assertStatus(200);

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    public function test_the_same_slug_is_valid_in_two_different_stores(): void
    {
        ['proprietaire' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['categories']);
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/boutiques/{$storeA->id}/categories", ['nom' => 'Boissons'])->assertStatus(201);

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['categories']);
        Sanctum::actingAs($ownerB);
        $this->postJson("/api/boutiques/{$storeB->id}/categories", ['nom' => 'Boissons'])->assertStatus(201);

        $this->assertDatabaseCount('categories', 2);
    }

    public function test_the_same_slug_is_rejected_twice_in_the_same_store(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['categories']);
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/categories", ['nom' => 'Boissons'])->assertStatus(201);

        $response = $this->postJson("/api/boutiques/{$store->id}/categories", ['nom' => 'Boissons']);

        $response->assertStatus(422)->assertJsonValidationErrors('slug', 'erreurs');
    }

    public function test_name_is_required(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['categories']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/categories", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nom', 'erreurs');
    }

    public function test_a_member_of_another_store_cannot_read_this_categorys_store(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['categories']);
        $category = Category::factory()->for($storeA)->create();

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['categories']);
        Sanctum::actingAs($ownerB);

        // storeB in the URL, but the category id belongs to storeA — must
        // 404 via scoped route model binding, never resolve.
        $this->getJson("/api/boutiques/{$storeB->id}/categories/{$category->id}")->assertStatus(404);
    }
}

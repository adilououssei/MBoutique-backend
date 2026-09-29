<?php

namespace Tests\Feature\Modules\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_a_product_has_no_image_by_default(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/produits", ['nom' => 'Sans photo', 'vente_detail_active' => true, 'prix_detail' => 100])
            ->assertStatus(201)
            ->assertJsonPath('donnees.image_url', null);
    }

    public function test_owner_can_upload_an_image_and_its_url_is_exposed(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $response = $this->post("/api/boutiques/{$store->id}/produits/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('coca.jpg', 600, 600),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(200);
        $path = $product->fresh()->image;
        $this->assertStringStartsWith("produits/{$store->id}/", $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith("/storage/{$path}", $response->json('donnees.image_url'));
    }

    public function test_replacing_the_image_deletes_the_previous_file(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $url = "/api/boutiques/{$store->id}/produits/{$product->id}/image";

        $this->post($url, ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertStatus(200);
        $first = $product->fresh()->image;
        $this->post($url, ['image' => UploadedFile::fake()->image('b.png')], ['Accept' => 'application/json'])->assertStatus(200);

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($product->fresh()->image);
    }

    public function test_owner_can_remove_the_image(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $url = "/api/boutiques/{$store->id}/produits/{$product->id}/image";
        $this->post($url, ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json']);
        $path = $product->fresh()->image;

        $this->deleteJson($url)->assertStatus(200)->assertJsonPath('donnees.image_url', null);

        $this->assertNull($product->fresh()->image);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->post("/api/boutiques/{$store->id}/produits/{$product->id}/image", [
            'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image', 'erreurs');
    }

    public function test_an_employee_cannot_change_a_product_image(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        $product = Product::factory()->for($store)->create();
        $employee = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $employee->email, 'role' => 'employe'])->assertStatus(201);

        Sanctum::actingAs($employee);

        $this->post("/api/boutiques/{$store->id}/produits/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('a.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_a_product_of_another_store_is_not_reachable(): void
    {
        ['proprietaire' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['produits']);
        ['store' => $storeB] = $this->createStoreWithFeatures(['produits']);
        $foreign = Product::factory()->for($storeB)->create();
        Sanctum::actingAs($ownerA);

        $this->post("/api/boutiques/{$storeA->id}/produits/{$foreign->id}/image", [
            'image' => UploadedFile::fake()->image('a.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(404);
    }
}

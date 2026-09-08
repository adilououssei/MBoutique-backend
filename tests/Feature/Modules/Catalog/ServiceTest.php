<?php

namespace Tests\Feature\Modules\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    public function test_owner_can_create_a_service(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/stores/{$store->id}/services", [
            'name' => 'Coupe homme',
            'price' => 2000,
            'duration_minutes' => 30,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Coupe homme')
            ->assertJsonPath('data.duration_minutes', 30);
    }

    public function test_price_is_stored_as_an_exact_decimal(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($owner);

        $id = $this->postJson("/api/stores/{$store->id}/services", ['name' => 'Manucure', 'price' => 1500.50])->json('data.id');

        $this->assertSame('1500.50', Service::find($id)->price);
    }

    public function test_duration_minutes_is_nullable(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/stores/{$store->id}/services", ['name' => 'Consultation', 'price' => 5000]);

        $response->assertStatus(201)->assertJsonPath('data.duration_minutes', null);
    }

    public function test_owner_can_list_read_update_and_delete_a_service(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        $service = Service::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/stores/{$store->id}/services")->assertStatus(200)->assertJsonCount(1, 'data');
        $this->getJson("/api/stores/{$store->id}/services/{$service->id}")->assertStatus(200);

        $this->putJson("/api/stores/{$store->id}/services/{$service->id}", ['name' => 'Renamed'])
            ->assertStatus(200)->assertJsonPath('data.name', 'Renamed');

        $this->deleteJson("/api/stores/{$store->id}/services/{$service->id}")->assertStatus(200);
        $this->assertSoftDeleted('services', ['id' => $service->id]);
    }

    public function test_price_is_required(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/services", ['name' => 'Consultation'])
            ->assertStatus(422)->assertJsonValidationErrors('price');
    }

    public function test_a_category_belonging_to_another_store_is_rejected(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['services', 'categories']);
        $categoryOfStoreA = Category::factory()->for($storeA)->create();

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($ownerB);

        $response = $this->postJson("/api/stores/{$storeB->id}/services", [
            'name' => 'Coupe', 'price' => 100, 'category_id' => $categoryOfStoreA->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('category_id');
    }

    public function test_a_member_of_another_store_cannot_reach_this_services_store(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['services']);
        $service = Service::factory()->for($storeA)->create();

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/stores/{$storeB->id}/services/{$service->id}")->assertStatus(404);
    }
}

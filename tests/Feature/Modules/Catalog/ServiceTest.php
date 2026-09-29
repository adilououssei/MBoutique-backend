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
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/boutiques/{$store->id}/services", [
            'nom' => 'Coupe homme',
            'prix' => 2000,
            'duree_minutes' => 30,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.nom', 'Coupe homme')
            ->assertJsonPath('donnees.duree_minutes', 30);
    }

    public function test_price_is_stored_as_an_exact_decimal(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($owner);

        $id = $this->postJson("/api/boutiques/{$store->id}/services", ['nom' => 'Manucure', 'prix' => 1500.50])->json('donnees.id');

        $this->assertSame('1500.50', Service::find($id)->prix);
    }

    public function test_duration_minutes_is_nullable(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/boutiques/{$store->id}/services", ['nom' => 'Consultation', 'prix' => 5000]);

        $response->assertStatus(201)->assertJsonPath('donnees.duree_minutes', null);
    }

    public function test_owner_can_list_read_update_and_delete_a_service(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        $service = Service::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/services")->assertStatus(200)->assertJsonCount(1, 'donnees');
        $this->getJson("/api/boutiques/{$store->id}/services/{$service->id}")->assertStatus(200);

        $this->putJson("/api/boutiques/{$store->id}/services/{$service->id}", ['nom' => 'Renamed'])
            ->assertStatus(200)->assertJsonPath('donnees.nom', 'Renamed');

        $this->deleteJson("/api/boutiques/{$store->id}/services/{$service->id}")->assertStatus(200);
        $this->assertSoftDeleted('services', ['id' => $service->id]);
    }

    public function test_price_is_required(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/services", ['nom' => 'Consultation'])
            ->assertStatus(422)->assertJsonValidationErrors('prix', 'erreurs');
    }

    public function test_a_category_belonging_to_another_store_is_rejected(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['services', 'categories']);
        $categoryOfStoreA = Category::factory()->for($storeA)->create();

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($ownerB);

        $response = $this->postJson("/api/boutiques/{$storeB->id}/services", [
            'nom' => 'Coupe', 'prix' => 100, 'categorie_id' => $categoryOfStoreA->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('categorie_id', 'erreurs');
    }

    public function test_a_member_of_another_store_cannot_reach_this_services_store(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['services']);
        $service = Service::factory()->for($storeA)->create();

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['services']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/boutiques/{$storeB->id}/services/{$service->id}")->assertStatus(404);
    }
}

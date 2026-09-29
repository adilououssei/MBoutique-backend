<?php

namespace Tests\Feature\Modules\Customers;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    // --- CRUD ---

    public function test_owner_can_create_a_customer_with_only_a_name(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['clients']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/boutiques/{$store->id}/clients", ['nom' => 'Kossi']);

        $response->assertStatus(201)->assertJsonPath('donnees.nom', 'Kossi');
        $this->assertDatabaseHas('clients', ['boutique_id' => $store->id, 'nom' => 'Kossi']);
    }

    public function test_owner_can_create_a_customer_with_full_details(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['clients']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/boutiques/{$store->id}/clients", [
            'nom' => 'Restaurant La Terrasse',
            'nom_entreprise' => 'La Terrasse SARL',
            'telephone' => '+228 90 00 00 00',
            'email' => 'contact@laterrasse.tg',
            'adresse' => '12 rue du Port',
            'notes' => 'Client régulier, commande le vendredi',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.nom_entreprise', 'La Terrasse SARL')
            ->assertJsonPath('donnees.email', 'contact@laterrasse.tg');
    }

    public function test_owner_can_list_read_update_and_delete_a_customer(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['clients']);
        $customer = Customer::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/clients")->assertStatus(200)->assertJsonCount(1, 'donnees');
        $this->getJson("/api/boutiques/{$store->id}/clients/{$customer->id}")->assertStatus(200);

        $this->putJson("/api/boutiques/{$store->id}/clients/{$customer->id}", ['nom' => 'Renamed'])
            ->assertStatus(200)->assertJsonPath('donnees.nom', 'Renamed');

        $this->deleteJson("/api/boutiques/{$store->id}/clients/{$customer->id}")->assertStatus(200);
        $this->assertSoftDeleted('clients', ['id' => $customer->id]);
    }

    // --- Validation ---

    public function test_name_is_required(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['clients']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/clients", [])
            ->assertStatus(422)->assertJsonValidationErrors('nom', 'erreurs');
    }

    public function test_email_must_be_a_valid_email_when_provided(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['clients']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/clients", ['nom' => 'Kossi', 'email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors('email', 'erreurs');
    }

    public function test_phone_email_company_name_address_and_notes_are_all_optional(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['clients']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/clients", ['nom' => 'Kossi'])->assertStatus(201);
    }

    public function test_the_same_name_is_valid_twice_in_the_same_store(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['clients']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/clients", ['nom' => 'Kossi'])->assertStatus(201);
        $this->postJson("/api/boutiques/{$store->id}/clients", ['nom' => 'Kossi'])->assertStatus(201);

        $this->assertDatabaseCount('clients', 2);
    }

    public function test_the_same_phone_is_valid_twice_in_the_same_store(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['clients']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/clients", ['nom' => 'Kossi', 'telephone' => '90000000'])->assertStatus(201);
        $this->postJson("/api/boutiques/{$store->id}/clients", ['nom' => 'Ama', 'telephone' => '90000000'])->assertStatus(201);

        $this->assertDatabaseCount('clients', 2);
    }

    // --- Isolation ---

    public function test_a_member_of_another_store_cannot_reach_this_customers_store(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['clients']);
        $customer = Customer::factory()->for($storeA)->create();

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['clients']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/boutiques/{$storeB->id}/clients/{$customer->id}")->assertStatus(404);
        $this->putJson("/api/boutiques/{$storeB->id}/clients/{$customer->id}", ['nom' => 'x'])->assertStatus(404);
        $this->deleteJson("/api/boutiques/{$storeB->id}/clients/{$customer->id}")->assertStatus(404);
    }

    public function test_two_stores_can_each_have_a_customer_with_the_same_name(): void
    {
        ['proprietaire' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['clients']);
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/boutiques/{$storeA->id}/clients", ['nom' => 'Kossi'])->assertStatus(201);

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['clients']);
        Sanctum::actingAs($ownerB);
        $this->postJson("/api/boutiques/{$storeB->id}/clients", ['nom' => 'Kossi'])->assertStatus(201);

        $this->assertDatabaseCount('clients', 2);
    }

    // --- Search ---

    public function test_search_matches_name_phone_or_company_name(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['clients']);
        Customer::factory()->for($store)->create(['nom' => 'Kossi Adjo', 'telephone' => '90111111', 'nom_entreprise' => null]);
        Customer::factory()->for($store)->create(['nom' => 'Ama Mensah', 'telephone' => '91222222', 'nom_entreprise' => null]);
        Customer::factory()->for($store)->create(['nom' => 'Restaurant', 'telephone' => '92333333', 'nom_entreprise' => 'La Terrasse SARL']);
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/clients?recherche=kossi")->assertStatus(200)->assertJsonCount(1, 'donnees');
        $this->getJson("/api/boutiques/{$store->id}/clients?recherche=91222222")->assertStatus(200)->assertJsonCount(1, 'donnees');
        $this->getJson("/api/boutiques/{$store->id}/clients?recherche=Terrasse")->assertStatus(200)->assertJsonCount(1, 'donnees');
        $this->getJson("/api/boutiques/{$store->id}/clients?recherche=nobody")->assertStatus(200)->assertJsonCount(0, 'donnees');
    }

    // --- Pagination ---

    public function test_list_is_paginated(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['clients']);
        Customer::factory()->for($store)->count(25)->create();
        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/boutiques/{$store->id}/clients?par_page=10");

        $response->assertStatus(200)->assertJsonCount(10, 'donnees')->assertJsonPath('meta.total', 25);
    }

    // --- Permissions ---

    public function test_an_employee_can_view_customers_but_not_create_one(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['clients']);
        $employee = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $employee->email, 'role' => 'employe'])
            ->assertStatus(201);

        Sanctum::actingAs($employee);

        $this->getJson("/api/boutiques/{$store->id}/clients")->assertStatus(200);
        $this->postJson("/api/boutiques/{$store->id}/clients", ['nom' => 'Kossi'])->assertStatus(403);
    }

    public function test_a_manager_can_create_a_customer(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['clients']);
        $manager = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $manager->email, 'role' => 'gerant'])
            ->assertStatus(201);

        Sanctum::actingAs($manager);

        $this->postJson("/api/boutiques/{$store->id}/clients", ['nom' => 'Kossi'])->assertStatus(201);
    }

    // --- Route model binding ---

    public function test_a_customer_belonging_to_another_store_404s_instead_of_leaking(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['clients']);
        $customerA = Customer::factory()->for($storeA)->create();

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['clients']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/boutiques/{$storeB->id}/clients/{$customerA->id}")->assertStatus(404);
    }
}

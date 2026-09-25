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
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['customers']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/stores/{$store->id}/customers", ['name' => 'Kossi']);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Kossi');
        $this->assertDatabaseHas('customers', ['store_id' => $store->id, 'name' => 'Kossi']);
    }

    public function test_owner_can_create_a_customer_with_full_details(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['customers']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/stores/{$store->id}/customers", [
            'name' => 'Restaurant La Terrasse',
            'company_name' => 'La Terrasse SARL',
            'phone' => '+228 90 00 00 00',
            'email' => 'contact@laterrasse.tg',
            'address' => '12 rue du Port',
            'notes' => 'Client régulier, commande le vendredi',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.company_name', 'La Terrasse SARL')
            ->assertJsonPath('data.email', 'contact@laterrasse.tg');
    }

    public function test_owner_can_list_read_update_and_delete_a_customer(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['customers']);
        $customer = Customer::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/stores/{$store->id}/customers")->assertStatus(200)->assertJsonCount(1, 'data');
        $this->getJson("/api/stores/{$store->id}/customers/{$customer->id}")->assertStatus(200);

        $this->putJson("/api/stores/{$store->id}/customers/{$customer->id}", ['name' => 'Renamed'])
            ->assertStatus(200)->assertJsonPath('data.name', 'Renamed');

        $this->deleteJson("/api/stores/{$store->id}/customers/{$customer->id}")->assertStatus(200);
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    // --- Validation ---

    public function test_name_is_required(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['customers']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/customers", [])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_email_must_be_a_valid_email_when_provided(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['customers']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/customers", ['name' => 'Kossi', 'email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_phone_email_company_name_address_and_notes_are_all_optional(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['customers']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/customers", ['name' => 'Kossi'])->assertStatus(201);
    }

    public function test_the_same_name_is_valid_twice_in_the_same_store(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['customers']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/customers", ['name' => 'Kossi'])->assertStatus(201);
        $this->postJson("/api/stores/{$store->id}/customers", ['name' => 'Kossi'])->assertStatus(201);

        $this->assertDatabaseCount('customers', 2);
    }

    public function test_the_same_phone_is_valid_twice_in_the_same_store(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['customers']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/customers", ['name' => 'Kossi', 'phone' => '90000000'])->assertStatus(201);
        $this->postJson("/api/stores/{$store->id}/customers", ['name' => 'Ama', 'phone' => '90000000'])->assertStatus(201);

        $this->assertDatabaseCount('customers', 2);
    }

    // --- Isolation ---

    public function test_a_member_of_another_store_cannot_reach_this_customers_store(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['customers']);
        $customer = Customer::factory()->for($storeA)->create();

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['customers']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/stores/{$storeB->id}/customers/{$customer->id}")->assertStatus(404);
        $this->putJson("/api/stores/{$storeB->id}/customers/{$customer->id}", ['name' => 'x'])->assertStatus(404);
        $this->deleteJson("/api/stores/{$storeB->id}/customers/{$customer->id}")->assertStatus(404);
    }

    public function test_two_stores_can_each_have_a_customer_with_the_same_name(): void
    {
        ['owner' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['customers']);
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/stores/{$storeA->id}/customers", ['name' => 'Kossi'])->assertStatus(201);

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['customers']);
        Sanctum::actingAs($ownerB);
        $this->postJson("/api/stores/{$storeB->id}/customers", ['name' => 'Kossi'])->assertStatus(201);

        $this->assertDatabaseCount('customers', 2);
    }

    // --- Search ---

    public function test_search_matches_name_phone_or_company_name(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['customers']);
        Customer::factory()->for($store)->create(['name' => 'Kossi Adjo', 'phone' => '90111111', 'company_name' => null]);
        Customer::factory()->for($store)->create(['name' => 'Ama Mensah', 'phone' => '91222222', 'company_name' => null]);
        Customer::factory()->for($store)->create(['name' => 'Restaurant', 'phone' => '92333333', 'company_name' => 'La Terrasse SARL']);
        Sanctum::actingAs($owner);

        $this->getJson("/api/stores/{$store->id}/customers?search=kossi")->assertStatus(200)->assertJsonCount(1, 'data');
        $this->getJson("/api/stores/{$store->id}/customers?search=91222222")->assertStatus(200)->assertJsonCount(1, 'data');
        $this->getJson("/api/stores/{$store->id}/customers?search=Terrasse")->assertStatus(200)->assertJsonCount(1, 'data');
        $this->getJson("/api/stores/{$store->id}/customers?search=nobody")->assertStatus(200)->assertJsonCount(0, 'data');
    }

    // --- Pagination ---

    public function test_list_is_paginated(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['customers']);
        Customer::factory()->for($store)->count(25)->create();
        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/stores/{$store->id}/customers?per_page=10");

        $response->assertStatus(200)->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 25);
    }

    // --- Permissions ---

    public function test_an_employee_can_view_customers_but_not_create_one(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['customers']);
        $employee = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/members", ['email' => $employee->email, 'role' => 'employee'])
            ->assertStatus(201);

        Sanctum::actingAs($employee);

        $this->getJson("/api/stores/{$store->id}/customers")->assertStatus(200);
        $this->postJson("/api/stores/{$store->id}/customers", ['name' => 'Kossi'])->assertStatus(403);
    }

    public function test_a_manager_can_create_a_customer(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['customers']);
        $manager = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/members", ['email' => $manager->email, 'role' => 'manager'])
            ->assertStatus(201);

        Sanctum::actingAs($manager);

        $this->postJson("/api/stores/{$store->id}/customers", ['name' => 'Kossi'])->assertStatus(201);
    }

    // --- Route model binding ---

    public function test_a_customer_belonging_to_another_store_404s_instead_of_leaking(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['customers']);
        $customerA = Customer::factory()->for($storeA)->create();

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['customers']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/stores/{$storeB->id}/customers/{$customerA->id}")->assertStatus(404);
    }
}

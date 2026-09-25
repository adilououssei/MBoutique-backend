<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private function checkoutOne(mixed $store, CashRegister $register, Product $product): int
    {
        return $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 1]],
            'cash_register_id' => $register->id,
        ])->json('data.id');
    }

    public function test_owner_can_list_and_read_a_sale(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory', 'cash_register', 'sales']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 0]);
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 600]);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 10]);
        $saleId = $this->checkoutOne($store, $register, $product);

        $this->getJson("/api/stores/{$store->id}/sales")->assertStatus(200)->assertJsonCount(1, 'data');
        $this->getJson("/api/stores/{$store->id}/sales/{$saleId}")->assertStatus(200)->assertJsonPath('data.id', $saleId);
    }

    public function test_sales_list_is_paginated(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['sales']);
        Sale::factory()->for($store)->count(15)->create();
        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/stores/{$store->id}/sales?per_page=10");

        $response->assertStatus(200)->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 15);
    }

    public function test_status_filter_narrows_the_list(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['sales']);
        Sale::factory()->for($store)->create(['status' => 'completed']);
        Sale::factory()->for($store)->create(['status' => 'cancelled']);
        Sanctum::actingAs($owner);

        $this->getJson("/api/stores/{$store->id}/sales?status=cancelled")
            ->assertStatus(200)->assertJsonCount(1, 'data');
    }

    // --- Isolation ---

    public function test_a_member_of_another_store_cannot_read_this_sale(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['sales']);
        $saleA = Sale::factory()->for($storeA)->create();

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['sales']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/stores/{$storeB->id}/sales/{$saleA->id}")->assertStatus(404);
    }

    // --- Permissions ---

    public function test_an_employee_can_view_sales_but_not_checkout(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory', 'cash_register', 'sales']);
        $employee = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/members", ['email' => $employee->email, 'role' => 'employee'])->assertStatus(201);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 0]);
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 600]);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 10]);

        Sanctum::actingAs($employee);

        $this->getJson("/api/stores/{$store->id}/sales")->assertStatus(200);
        $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 1]],
            'cash_register_id' => $register->id,
        ])->assertStatus(403);
    }

    public function test_a_cashier_can_checkout(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory', 'cash_register', 'sales']);
        $cashier = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/members", ['email' => $cashier->email, 'role' => 'cashier'])->assertStatus(201);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 0]);
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 600]);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 10]);

        Sanctum::actingAs($cashier);

        $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 1]],
            'cash_register_id' => $register->id,
        ])->assertStatus(201);
    }

    // --- FeatureGate ---

    public function test_sales_is_blocked_when_the_domain_never_enabled_the_feature(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']); // sales NOT enabled
        Sanctum::actingAs($owner);

        $this->getJson("/api/stores/{$store->id}/sales")
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }
}

<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    // --- Initial stock ---

    public function test_owner_can_record_the_initial_stock_of_a_product(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", [
            'type' => 'initial',
            'quantity' => 100,
            'minimum_quantity' => 10,
            'reason' => 'Stock de départ',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'initial')
            ->assertJsonPath('data.quantity', '100.000')
            ->assertJsonPath('data.quantity_before', '0.000')
            ->assertJsonPath('data.quantity_after', '100.000');

        $this->assertDatabaseHas('stocks', [
            'store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 100, 'minimum_quantity' => 10,
        ]);
    }

    public function test_stock_cannot_be_initialized_twice(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 100])
            ->assertStatus(201);

        $response = $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 50]);

        $response->assertStatus(422)->assertJsonPath('code', 'STOCK_ALREADY_INITIALIZED');
        $this->assertSame('100.000', Stock::where('product_id', $product->id)->first()->quantity);
    }

    public function test_a_non_initial_movement_requires_stock_to_already_exist(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'purchase', 'quantity' => 50]);

        $response->assertStatus(422)->assertJsonPath('code', 'STOCK_NOT_INITIALIZED');
    }

    // --- Entry / exit ---

    public function test_a_purchase_increases_the_stock(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 100]);

        $response = $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'purchase', 'quantity' => 50]);

        $response->assertStatus(201)
            ->assertJsonPath('data.quantity', '50.000')
            ->assertJsonPath('data.quantity_before', '100.000')
            ->assertJsonPath('data.quantity_after', '150.000');
    }

    public function test_a_loss_decreases_the_stock(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 100]);

        $response = $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", [
            'type' => 'loss', 'quantity' => 20, 'reason' => 'Produit endommagé',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.quantity', '-20.000')
            ->assertJsonPath('data.quantity_after', '80.000');
    }

    public function test_removing_more_than_available_is_rejected(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 10]);

        $response = $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'loss', 'quantity' => 15]);

        $response->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
        // Rejected atomically — the stock must be untouched, not partially decremented.
        $this->assertSame('10.000', Stock::where('product_id', $product->id)->first()->quantity);
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->count());
    }

    /**
     * A genuine multi-connection race isn't reproducible against SQLite
     * in-memory (the test process holds the only connection) — see
     * docs/inventory.md §"Concurrence" for the acknowledged limitation.
     * This proves the sequential business outcome InventoryService must
     * guarantee once locking serializes the real concurrent case: two
     * exits that individually look valid but together overdraw the
     * stock must not both succeed.
     */
    public function test_two_sequential_exits_that_together_exceed_stock_cannot_both_succeed(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 5]);

        $first = $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'loss', 'quantity' => 4]);
        $second = $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'loss', 'quantity' => 3]);

        $first->assertStatus(201);
        $second->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
        $this->assertSame('1.000', Stock::where('product_id', $product->id)->first()->quantity);
    }

    // --- Stocktake ---

    public function test_stocktake_records_the_delta_between_counted_and_system_quantity(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 100]);

        $response = $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", [
            'type' => 'stocktake', 'counted_quantity' => 96, 'reason' => 'Inventaire mensuel',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'stocktake')
            ->assertJsonPath('data.quantity', '-4.000')
            ->assertJsonPath('data.quantity_before', '100.000')
            ->assertJsonPath('data.quantity_after', '96.000');
    }

    public function test_stocktake_requires_counted_quantity_not_quantity(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 100]);

        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'stocktake', 'quantity' => 96])
            ->assertStatus(422)->assertJsonValidationErrors('counted_quantity');
    }

    // --- Ledger / immutability ---

    public function test_history_exposes_quantity_before_and_after_for_every_movement(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 100]);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'purchase', 'quantity' => 50]);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'loss', 'quantity' => 20]);

        $response = $this->getJson("/api/stores/{$store->id}/inventory/{$product->id}/movements");

        $response->assertStatus(200)->assertJsonCount(3, 'data');
        $types = collect($response->json('data'))->pluck('type');
        $this->assertEquals(['loss', 'purchase', 'initial'], $types->all()); // latest first
    }

    public function test_a_stock_movement_cannot_be_updated_or_deleted_through_the_api(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $id = $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 100])
            ->json('data.id');

        // No such routes exist at all — a correction is a new movement, never an edit.
        $this->putJson("/api/stores/{$store->id}/inventory/movements/{$id}", ['quantity' => 999])->assertStatus(404);
        $this->deleteJson("/api/stores/{$store->id}/inventory/movements/{$id}")->assertStatus(404);
    }

    // --- Multi-tenant isolation ---

    public function test_a_product_from_another_store_cannot_be_used_for_a_movement(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['products', 'inventory']);
        $productA = Product::factory()->for($storeA)->create();

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['products', 'inventory']);
        Sanctum::actingAs($ownerB);

        $this->postJson("/api/stores/{$storeB->id}/inventory/{$productA->id}/movements", ['type' => 'initial', 'quantity' => 100])
            ->assertStatus(404);
    }

    public function test_a_member_of_another_store_cannot_read_this_products_movement_history(): void
    {
        ['owner' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['products', 'inventory']);
        $productA = Product::factory()->for($storeA)->create();
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/stores/{$storeA->id}/inventory/{$productA->id}/movements", ['type' => 'initial', 'quantity' => 100]);

        ['owner' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['products', 'inventory']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/stores/{$storeB->id}/inventory/{$productA->id}/movements")->assertStatus(404);
    }

    // --- Permissions ---

    public function test_an_employee_can_view_movements_but_not_record_one(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        $employee = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/members", ['email' => $employee->email, 'role' => 'employee'])->assertStatus(201);

        Sanctum::actingAs($employee);

        $this->getJson("/api/stores/{$store->id}/inventory/{$product->id}/movements")->assertStatus(200);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 100])
            ->assertStatus(403);
    }

    public function test_a_manager_can_record_a_purchase(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        $manager = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/members", ['email' => $manager->email, 'role' => 'manager'])->assertStatus(201);

        Sanctum::actingAs($manager);

        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 100])
            ->assertStatus(201);
    }

    public function test_inventory_adjust_and_inventory_stocktake_are_independent_permissions(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory']);
        $product = Product::factory()->for($store)->create();
        $employee = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/members", ['email' => $employee->email, 'role' => 'employee'])->assertStatus(201);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => 100]);

        app(TenantContextContract::class)->setStoreId($store->id);
        $employee->givePermissionTo('inventory.adjust');
        app(TenantContextContract::class)->clear();

        Sanctum::actingAs($employee);

        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'purchase', 'quantity' => 10])
            ->assertStatus(201);
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'stocktake', 'counted_quantity' => 50])
            ->assertStatus(403);
    }
}

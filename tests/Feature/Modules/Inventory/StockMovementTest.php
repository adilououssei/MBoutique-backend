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
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", [
            'type' => 'initial',
            'quantite' => 100,
            'quantite_minimum' => 10,
            'motif' => 'Stock de départ',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.type', 'initial')
            ->assertJsonPath('donnees.quantite', '100.000')
            ->assertJsonPath('donnees.quantite_avant', '0.000')
            ->assertJsonPath('donnees.quantite_apres', '100.000');

        $this->assertDatabaseHas('stocks', [
            'boutique_id' => $store->id, 'produit_id' => $product->id, 'quantite' => 100, 'quantite_minimum' => 10,
        ]);
    }

    public function test_stock_cannot_be_initialized_twice(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 100])
            ->assertStatus(201);

        $response = $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 50]);

        $response->assertStatus(422)->assertJsonPath('code', 'STOCK_DEJA_INITIALISE');
        $this->assertSame('100.000', Stock::where('produit_id', $product->id)->first()->quantite);
    }

    public function test_a_non_initial_movement_requires_stock_to_already_exist(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'achat', 'quantite' => 50]);

        $response->assertStatus(422)->assertJsonPath('code', 'STOCK_NON_INITIALISE');
    }

    // --- Entry / exit ---

    public function test_a_purchase_increases_the_stock(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 100]);

        $response = $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'achat', 'quantite' => 50]);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.quantite', '50.000')
            ->assertJsonPath('donnees.quantite_avant', '100.000')
            ->assertJsonPath('donnees.quantite_apres', '150.000');
    }

    public function test_a_loss_decreases_the_stock(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 100]);

        $response = $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", [
            'type' => 'perte', 'quantite' => 20, 'motif' => 'Produit endommagé',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.quantite', '-20.000')
            ->assertJsonPath('donnees.quantite_apres', '80.000');
    }

    public function test_removing_more_than_available_is_rejected(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 10]);

        $response = $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'perte', 'quantite' => 15]);

        $response->assertStatus(422)->assertJsonPath('code', 'STOCK_INSUFFISANT');
        // Rejected atomically — the stock must be untouched, not partially decremented.
        $this->assertSame('10.000', Stock::where('produit_id', $product->id)->first()->quantite);
        $this->assertSame(1, StockMovement::where('produit_id', $product->id)->count());
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
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 5]);

        $first = $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'perte', 'quantite' => 4]);
        $second = $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'perte', 'quantite' => 3]);

        $first->assertStatus(201);
        $second->assertStatus(422)->assertJsonPath('code', 'STOCK_INSUFFISANT');
        $this->assertSame('1.000', Stock::where('produit_id', $product->id)->first()->quantite);
    }

    // --- Stocktake ---

    public function test_stocktake_records_the_delta_between_counted_and_system_quantity(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 100]);

        $response = $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", [
            'type' => 'inventaire', 'quantite_comptee' => 96, 'motif' => 'Inventaire mensuel',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.type', 'inventaire')
            ->assertJsonPath('donnees.quantite', '-4.000')
            ->assertJsonPath('donnees.quantite_avant', '100.000')
            ->assertJsonPath('donnees.quantite_apres', '96.000');
    }

    public function test_stocktake_requires_counted_quantity_not_quantity(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 100]);

        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'inventaire', 'quantite' => 96])
            ->assertStatus(422)->assertJsonValidationErrors('quantite_comptee', 'erreurs');
    }

    // --- Ledger / immutability ---

    public function test_history_exposes_quantity_before_and_after_for_every_movement(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 100]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'achat', 'quantite' => 50]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'perte', 'quantite' => 20]);

        $response = $this->getJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements");

        $response->assertStatus(200)->assertJsonCount(3, 'donnees');
        $types = collect($response->json('donnees'))->pluck('type');
        $this->assertEquals(['perte', 'achat', 'initial'], $types->all()); // latest first
    }

    public function test_a_stock_movement_cannot_be_updated_or_deleted_through_the_api(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        Sanctum::actingAs($owner);
        $id = $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 100])
            ->json('donnees.id');

        // No such routes exist at all — a correction is a new movement, never an edit.
        $this->putJson("/api/boutiques/{$store->id}/stocks/mouvements/{$id}", ['quantite' => 999])->assertStatus(404);
        $this->deleteJson("/api/boutiques/{$store->id}/stocks/mouvements/{$id}")->assertStatus(404);
    }

    // --- Multi-tenant isolation ---

    public function test_a_product_from_another_store_cannot_be_used_for_a_movement(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['produits', 'stock']);
        $productA = Product::factory()->for($storeA)->create();

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['produits', 'stock']);
        Sanctum::actingAs($ownerB);

        $this->postJson("/api/boutiques/{$storeB->id}/stocks/{$productA->id}/mouvements", ['type' => 'initial', 'quantite' => 100])
            ->assertStatus(404);
    }

    public function test_a_member_of_another_store_cannot_read_this_products_movement_history(): void
    {
        ['proprietaire' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['produits', 'stock']);
        $productA = Product::factory()->for($storeA)->create();
        Sanctum::actingAs($ownerA);
        $this->postJson("/api/boutiques/{$storeA->id}/stocks/{$productA->id}/mouvements", ['type' => 'initial', 'quantite' => 100]);

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['produits', 'stock']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/boutiques/{$storeB->id}/stocks/{$productA->id}/mouvements")->assertStatus(404);
    }

    // --- Permissions ---

    public function test_an_employee_can_view_movements_but_not_record_one(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        $employee = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $employee->email, 'role' => 'employe'])->assertStatus(201);

        Sanctum::actingAs($employee);

        $this->getJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements")->assertStatus(200);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 100])
            ->assertStatus(403);
    }

    public function test_a_manager_can_record_a_purchase(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        $manager = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $manager->email, 'role' => 'gerant'])->assertStatus(201);

        Sanctum::actingAs($manager);

        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 100])
            ->assertStatus(201);
    }

    public function test_inventory_adjust_and_inventory_stocktake_are_independent_permissions(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $product = Product::factory()->for($store)->create();
        $employee = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $employee->email, 'role' => 'employe'])->assertStatus(201);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 100]);

        app(TenantContextContract::class)->setStoreId($store->id);
        $employee->givePermissionTo('stock.ajuster');
        app(TenantContextContract::class)->clear();

        Sanctum::actingAs($employee);

        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'achat', 'quantite' => 10])
            ->assertStatus(201);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'inventaire', 'quantite_comptee' => 50])
            ->assertStatus(403);
    }
}

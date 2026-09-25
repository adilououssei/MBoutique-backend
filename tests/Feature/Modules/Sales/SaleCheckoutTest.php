<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class SaleCheckoutTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{owner: User, store: Store, register: CashRegister} */
    private function readyStore(array $features = ['products', 'inventory', 'cash_register', 'sales', 'customers']): array
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures($features);
        Sanctum::actingAs($owner);

        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions", ['opening_amount' => 0])
            ->assertStatus(201);

        return ['owner' => $owner, 'store' => $store, 'register' => $register];
    }

    private function stockUp(Store $store, Product $product, float $quantity = 100): void
    {
        $this->postJson("/api/stores/{$store->id}/inventory/{$product->id}/movements", ['type' => 'initial', 'quantity' => $quantity])
            ->assertStatus(201);
    }

    public function test_a_simple_retail_sale_completes(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 600, 'wholesale_enabled' => false]);
        $this->stockUp($store, $product);

        $response = $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 3]],
            'cash_register_id' => $register->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.subtotal', '1800.00')
            ->assertJsonPath('data.total', '1800.00')
            ->assertJsonPath('data.items.0.unit_price', '600.00')
            ->assertJsonPath('data.items.0.quantity', '3.000');

        $this->assertNotNull($response->json('data.reference'));
        $this->assertSame(97.0, (float) Stock::where('product_id', $product->id)->first()->quantity);
    }

    public function test_a_sale_with_multiple_items_sums_correctly(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $a = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 1000]);
        $b = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 500]);
        $this->stockUp($store, $a);
        $this->stockUp($store, $b);

        $response = $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [
                ['product_id' => $a->id, 'pricing_mode' => 'retail', 'quantity' => 2], // 2000
                ['product_id' => $b->id, 'pricing_mode' => 'retail', 'quantity' => 3], // 1500
            ],
            'cash_register_id' => $register->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.subtotal', '3500.00')
            ->assertJsonPath('data.total', '3500.00')
            ->assertJsonCount(2, 'data.items');
    }

    public function test_wholesale_pricing_uses_the_wholesale_price(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create([
            'retail_enabled' => true, 'retail_price' => 600,
            'wholesale_enabled' => true, 'wholesale_price' => 550,
        ]);
        $this->stockUp($store, $product);

        $response = $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'wholesale', 'quantity' => 10]],
            'cash_register_id' => $register->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.items.0.unit_price', '550.00')
            ->assertJsonPath('data.total', '5500.00');
    }

    public function test_retail_mode_is_rejected_when_retail_is_disabled(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create([
            'retail_enabled' => false, 'retail_price' => null,
            'wholesale_enabled' => true, 'wholesale_price' => 550,
        ]);
        $this->stockUp($store, $product);

        $response = $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 1]],
            'cash_register_id' => $register->id,
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'PRICING_MODE_NOT_AVAILABLE');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_wholesale_mode_is_rejected_when_wholesale_is_disabled(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 600, 'wholesale_enabled' => false]);
        $this->stockUp($store, $product);

        $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'wholesale', 'quantity' => 1]],
            'cash_register_id' => $register->id,
        ])->assertStatus(422)->assertJsonPath('code', 'PRICING_MODE_NOT_AVAILABLE');
    }

    // --- Stock ---

    public function test_sale_is_rejected_when_stock_is_insufficient(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 600]);
        $this->stockUp($store, $product, 5);

        $response = $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 10]],
            'cash_register_id' => $register->id,
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(5.0, (float) Stock::where('product_id', $product->id)->first()->quantity);
    }

    public function test_a_failing_sale_does_not_partially_decrement_stock_or_cash(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $a = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 1000]);
        $b = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 500]);
        $this->stockUp($store, $a, 100); // plenty
        $this->stockUp($store, $b, 1);   // not enough for the requested quantity below

        $response = $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [
                ['product_id' => $a->id, 'pricing_mode' => 'retail', 'quantity' => 5], // would succeed alone
                ['product_id' => $b->id, 'pricing_mode' => 'retail', 'quantity' => 5], // fails: only 1 in stock
            ],
            'cash_register_id' => $register->id,
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        // Product A's stock must be untouched even though its own line would have succeeded in isolation.
        $this->assertSame(100.0, (float) Stock::where('product_id', $a->id)->first()->quantity);

        $sessionId = CashRegisterSession::where('cash_register_id', $register->id)->value('id');
        $this->assertDatabaseCount('cash_movements', 1); // only the opening movement
    }

    // --- Cash register ---

    public function test_sale_is_rejected_when_no_session_is_open(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'inventory', 'cash_register', 'sales']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create(); // never opened
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 600]);
        $this->stockUp($store, $product);

        $response = $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 1]],
            'cash_register_id' => $register->id,
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'NO_OPEN_CASH_REGISTER_SESSION');
    }

    public function test_the_sale_amount_is_added_to_the_cash_ledger(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 600]);
        $this->stockUp($store, $product);

        $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 3]],
            'cash_register_id' => $register->id,
        ])->assertStatus(201);

        $sessionId = CashRegisterSession::where('cash_register_id', $register->id)->value('id');
        $movements = $this->getJson("/api/stores/{$store->id}/cash-registers/{$register->id}/sessions/{$sessionId}/movements")->json('data');

        $sale = collect($movements)->firstWhere('type', 'sale');
        $this->assertNotNull($sale);
        $this->assertSame('1800.00', $sale['amount']);
    }

    // --- Customer ---

    public function test_a_sale_can_be_created_without_a_customer(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 600]);
        $this->stockUp($store, $product);

        $response = $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 1]],
            'cash_register_id' => $register->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.customer', null);
        $this->assertDatabaseCount('customers', 0); // never auto-created
    }

    public function test_a_sale_can_be_associated_with_a_customer(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 600]);
        $this->stockUp($store, $product);
        $customer = Customer::factory()->for($store)->create(['name' => 'Kossi']);

        $response = $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 1]],
            'cash_register_id' => $register->id,
            'customer_id' => $customer->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.customer.name', 'Kossi');
    }

    public function test_a_customer_from_another_store_is_rejected(): void
    {
        ['owner' => $owner, 'store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 600]);
        $this->stockUp($store, $product);

        ['store' => $otherStore] = $this->createStoreWithFeatures(['customers']);
        $foreignCustomer = Customer::factory()->for($otherStore)->create();
        Sanctum::actingAs($owner); // createStoreWithFeatures() above switched the acting user

        $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 1]],
            'cash_register_id' => $register->id,
            'customer_id' => $foreignCustomer->id,
        ])->assertStatus(422)->assertJsonValidationErrors('customer_id');
    }

    // --- Idempotence ---

    public function test_the_same_idempotency_key_does_not_create_two_sales(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 600]);
        $this->stockUp($store, $product, 100);

        $payload = [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 2]],
            'cash_register_id' => $register->id,
            'idempotency_key' => 'abc-123',
        ];

        $first = $this->postJson("/api/stores/{$store->id}/sales/checkout", $payload);
        $second = $this->postJson("/api/stores/{$store->id}/sales/checkout", $payload);

        $first->assertStatus(201);
        $second->assertStatus(200);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('sales', 1);
        // No double stock decrement either.
        $this->assertSame(98.0, (float) Stock::where('product_id', $product->id)->first()->quantity);
    }

    // --- Multi-tenancy ---

    public function test_a_product_from_another_store_cannot_be_sold(): void
    {
        ['owner' => $owner, 'store' => $store, 'register' => $register] = $this->readyStore();
        ['store' => $otherStore] = $this->createStoreWithFeatures(['products']);
        $foreignProduct = Product::factory()->for($otherStore)->create(['retail_enabled' => true, 'retail_price' => 600]);
        Sanctum::actingAs($owner); // createStoreWithFeatures() above switched the acting user

        $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $foreignProduct->id, 'pricing_mode' => 'retail', 'quantity' => 1]],
            'cash_register_id' => $register->id,
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');
    }

    public function test_a_cash_register_from_another_store_cannot_be_used(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 600]);
        $this->stockUp($store, $product);

        ['register' => $foreignRegister] = $this->readyStore();
        Sanctum::actingAs($owner); // readyStore() above switched the acting user

        $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 1]],
            'cash_register_id' => $foreignRegister->id,
        ])->assertStatus(422)->assertJsonValidationErrors('cash_register_id');
    }

    // --- Snapshot ---

    public function test_sale_item_keeps_the_price_and_name_at_time_of_sale(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['name' => 'Coca-Cola 50cl', 'retail_enabled' => true, 'retail_price' => 600]);
        $this->stockUp($store, $product);

        $saleId = $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 1]],
            'cash_register_id' => $register->id,
        ])->json('data.id');

        $product->update(['name' => 'Coca-Cola 50cl (renommé)', 'retail_price' => 900]);

        $sale = $this->getJson("/api/stores/{$store->id}/sales/{$saleId}")->json('data');

        $this->assertSame('Coca-Cola 50cl', $sale['items'][0]['product_name']);
        $this->assertSame('600.00', $sale['items'][0]['unit_price']);
    }

    // --- Discount ---

    public function test_discount_reduces_the_total(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 1000]);
        $this->stockUp($store, $product);

        $response = $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 1]],
            'cash_register_id' => $register->id,
            'discount_amount' => 100,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.discount', '100.00')->assertJsonPath('data.total', '900.00');
    }

    public function test_discount_cannot_exceed_the_subtotal(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['retail_enabled' => true, 'retail_price' => 1000]);
        $this->stockUp($store, $product);

        $this->postJson("/api/stores/{$store->id}/sales/checkout", [
            'items' => [['product_id' => $product->id, 'pricing_mode' => 'retail', 'quantity' => 1]],
            'cash_register_id' => $register->id,
            'discount_amount' => 5000,
        ])->assertStatus(422)->assertJsonPath('code', 'INVALID_DISCOUNT');
    }
}

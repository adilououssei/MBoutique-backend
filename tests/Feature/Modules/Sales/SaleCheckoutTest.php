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
    private function readyStore(array $features = ['produits', 'stock', 'caisse', 'ventes', 'clients']): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures($features);
        Sanctum::actingAs($owner);

        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])
            ->assertStatus(201);

        return ['proprietaire' => $owner, 'store' => $store, 'register' => $register];
    }

    private function stockUp(Store $store, Product $product, float $quantity = 100): void
    {
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => $quantity])
            ->assertStatus(201);
    }

    public function test_a_simple_retail_sale_completes(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 600, 'vente_gros_active' => false]);
        $this->stockUp($store, $product);

        $response = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 3]],
            'caisse_id' => $register->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.statut', 'terminee')
            ->assertJsonPath('donnees.sous_total', '1800.00')
            ->assertJsonPath('donnees.total', '1800.00')
            ->assertJsonPath('donnees.lignes.0.prix_unitaire', '600.00')
            ->assertJsonPath('donnees.lignes.0.quantite', '3.000');

        $this->assertNotNull($response->json('donnees.reference'));
        $this->assertSame(97.0, (float) Stock::where('produit_id', $product->id)->first()->quantite);
    }

    public function test_a_sale_with_multiple_items_sums_correctly(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $a = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 1000]);
        $b = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 500]);
        $this->stockUp($store, $a);
        $this->stockUp($store, $b);

        $response = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [
                ['produit_id' => $a->id, 'mode_prix' => 'detail', 'quantite' => 2], // 2000
                ['produit_id' => $b->id, 'mode_prix' => 'detail', 'quantite' => 3], // 1500
            ],
            'caisse_id' => $register->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.sous_total', '3500.00')
            ->assertJsonPath('donnees.total', '3500.00')
            ->assertJsonCount(2, 'donnees.lignes');
    }

    public function test_wholesale_pricing_uses_the_wholesale_price(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create([
            'vente_detail_active' => true, 'prix_detail' => 600,
            'vente_gros_active' => true, 'prix_gros' => 550,
        ]);
        $this->stockUp($store, $product);

        $response = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'gros', 'quantite' => 10]],
            'caisse_id' => $register->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('donnees.lignes.0.prix_unitaire', '550.00')
            ->assertJsonPath('donnees.total', '5500.00');
    }

    public function test_retail_mode_is_rejected_when_retail_is_disabled(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create([
            'vente_detail_active' => false, 'prix_detail' => null,
            'vente_gros_active' => true, 'prix_gros' => 550,
        ]);
        $this->stockUp($store, $product);

        $response = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1]],
            'caisse_id' => $register->id,
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'MODE_PRIX_INDISPONIBLE');
        $this->assertDatabaseCount('ventes', 0);
    }

    public function test_wholesale_mode_is_rejected_when_wholesale_is_disabled(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 600, 'vente_gros_active' => false]);
        $this->stockUp($store, $product);

        $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'gros', 'quantite' => 1]],
            'caisse_id' => $register->id,
        ])->assertStatus(422)->assertJsonPath('code', 'MODE_PRIX_INDISPONIBLE');
    }

    // --- Stock ---

    public function test_sale_is_rejected_when_stock_is_insufficient(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 600]);
        $this->stockUp($store, $product, 5);

        $response = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 10]],
            'caisse_id' => $register->id,
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'STOCK_INSUFFISANT');
        $this->assertDatabaseCount('ventes', 0);
        $this->assertSame(5.0, (float) Stock::where('produit_id', $product->id)->first()->quantite);
    }

    public function test_a_failing_sale_does_not_partially_decrement_stock_or_cash(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $a = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 1000]);
        $b = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 500]);
        $this->stockUp($store, $a, 100); // plenty
        $this->stockUp($store, $b, 1);   // not enough for the requested quantity below

        $response = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [
                ['produit_id' => $a->id, 'mode_prix' => 'detail', 'quantite' => 5], // would succeed alone
                ['produit_id' => $b->id, 'mode_prix' => 'detail', 'quantite' => 5], // fails: only 1 in stock
            ],
            'caisse_id' => $register->id,
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'STOCK_INSUFFISANT');
        $this->assertDatabaseCount('ventes', 0);
        $this->assertDatabaseCount('lignes_vente', 0);
        // Product A's stock must be untouched even though its own line would have succeeded in isolation.
        $this->assertSame(100.0, (float) Stock::where('produit_id', $a->id)->first()->quantite);

        $sessionId = CashRegisterSession::where('caisse_id', $register->id)->value('id');
        $this->assertDatabaseCount('mouvements_caisse', 1); // only the opening movement
    }

    // --- Cash register ---

    public function test_sale_is_rejected_when_no_session_is_open(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock', 'caisse', 'ventes']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create(); // never opened
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 600]);
        $this->stockUp($store, $product);

        $response = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1]],
            'caisse_id' => $register->id,
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'AUCUNE_SESSION_CAISSE_OUVERTE');
    }

    public function test_the_sale_amount_is_added_to_the_cash_ledger(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 600]);
        $this->stockUp($store, $product);

        $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 3]],
            'caisse_id' => $register->id,
        ])->assertStatus(201);

        $sessionId = CashRegisterSession::where('caisse_id', $register->id)->value('id');
        $movements = $this->getJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$sessionId}/mouvements")->json('donnees');

        $sale = collect($movements)->firstWhere('type', 'vente');
        $this->assertNotNull($sale);
        $this->assertSame('1800.00', $sale['montant']);
    }

    // --- Customer ---

    public function test_a_sale_can_be_created_without_a_customer(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 600]);
        $this->stockUp($store, $product);

        $response = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1]],
            'caisse_id' => $register->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('donnees.client', null);
        $this->assertDatabaseCount('clients', 0); // never auto-created
    }

    public function test_a_sale_can_be_associated_with_a_customer(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 600]);
        $this->stockUp($store, $product);
        $customer = Customer::factory()->for($store)->create(['nom' => 'Kossi']);

        $response = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1]],
            'caisse_id' => $register->id,
            'client_id' => $customer->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('donnees.client.nom', 'Kossi');
    }

    public function test_a_customer_from_another_store_is_rejected(): void
    {
        ['proprietaire' => $owner, 'store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 600]);
        $this->stockUp($store, $product);

        ['store' => $otherStore] = $this->createStoreWithFeatures(['clients']);
        $foreignCustomer = Customer::factory()->for($otherStore)->create();
        Sanctum::actingAs($owner); // createStoreWithFeatures() above switched the acting user

        $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1]],
            'caisse_id' => $register->id,
            'client_id' => $foreignCustomer->id,
        ])->assertStatus(422)->assertJsonValidationErrors('client_id', 'erreurs');
    }

    // --- Idempotence ---

    public function test_the_same_idempotency_key_does_not_create_two_sales(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 600]);
        $this->stockUp($store, $product, 100);

        $payload = [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 2]],
            'caisse_id' => $register->id,
            'cle_idempotence' => 'abc-123',
        ];

        $first = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", $payload);
        $second = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", $payload);

        $first->assertStatus(201);
        $second->assertStatus(200);
        $this->assertSame($first->json('donnees.id'), $second->json('donnees.id'));
        $this->assertDatabaseCount('ventes', 1);
        // No double stock decrement either.
        $this->assertSame(98.0, (float) Stock::where('produit_id', $product->id)->first()->quantite);
    }

    // --- Multi-tenancy ---

    public function test_a_product_from_another_store_cannot_be_sold(): void
    {
        ['proprietaire' => $owner, 'store' => $store, 'register' => $register] = $this->readyStore();
        ['store' => $otherStore] = $this->createStoreWithFeatures(['produits']);
        $foreignProduct = Product::factory()->for($otherStore)->create(['vente_detail_active' => true, 'prix_detail' => 600]);
        Sanctum::actingAs($owner); // createStoreWithFeatures() above switched the acting user

        $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $foreignProduct->id, 'mode_prix' => 'detail', 'quantite' => 1]],
            'caisse_id' => $register->id,
        ])->assertStatus(422)->assertJsonValidationErrors('lignes.0.produit_id', 'erreurs');
    }

    public function test_a_cash_register_from_another_store_cannot_be_used(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 600]);
        $this->stockUp($store, $product);

        ['register' => $foreignRegister] = $this->readyStore();
        Sanctum::actingAs($owner); // readyStore() above switched the acting user

        $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1]],
            'caisse_id' => $foreignRegister->id,
        ])->assertStatus(422)->assertJsonValidationErrors('caisse_id', 'erreurs');
    }

    // --- Snapshot ---

    public function test_sale_item_keeps_the_price_and_name_at_time_of_sale(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['nom' => 'Coca-Cola 50cl', 'vente_detail_active' => true, 'prix_detail' => 600]);
        $this->stockUp($store, $product);

        $saleId = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1]],
            'caisse_id' => $register->id,
        ])->json('donnees.id');

        $product->update(['nom' => 'Coca-Cola 50cl (renommé)', 'prix_detail' => 900]);

        $sale = $this->getJson("/api/boutiques/{$store->id}/ventes/{$saleId}")->json('donnees');

        $this->assertSame('Coca-Cola 50cl', $sale['lignes'][0]['nom_produit']);
        $this->assertSame('600.00', $sale['lignes'][0]['prix_unitaire']);
    }

    // --- Discount ---

    public function test_discount_reduces_the_total(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 1000]);
        $this->stockUp($store, $product);

        $response = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1]],
            'caisse_id' => $register->id,
            'montant_remise' => 100,
        ]);

        $response->assertStatus(201)->assertJsonPath('donnees.remise', '100.00')->assertJsonPath('donnees.total', '900.00');
    }

    public function test_discount_cannot_exceed_the_subtotal(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 1000]);
        $this->stockUp($store, $product);

        $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1]],
            'caisse_id' => $register->id,
            'montant_remise' => 5000,
        ])->assertStatus(422)->assertJsonPath('code', 'REMISE_INVALIDE');
    }
}

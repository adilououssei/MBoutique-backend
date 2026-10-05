<?php

namespace Tests\Feature\Modules\Sales;

use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Vente de services, remises par ligne, stock non initialisé — docs/sales.md §21-22. */
class SaleServicesAndDiscountsTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{store: Store, register: CashRegister} */
    private function readyStore(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'services', 'stock', 'caisse', 'ventes', 'rapports']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);

        return ['store' => $store, 'register' => $register];
    }

    private function stockedProduct(Store $store, float $price = 600): Product
    {
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => $price, 'vente_gros_active' => false]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 50])->assertStatus(201);

        return $product;
    }

    private function checkout(Store $store, CashRegister $register, array $lines, array $extra = [])
    {
        return $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", ['lignes' => $lines, 'caisse_id' => $register->id, ...$extra]);
    }

    public function test_a_service_can_be_sold_at_its_server_side_price_without_stock(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $service = Service::factory()->for($store)->create(['nom' => 'Coupe homme', 'prix' => 2000]);

        $this->checkout($store, $register, [['service_id' => $service->id, 'quantite' => 2]])
            ->assertStatus(201)
            ->assertJsonPath('donnees.total', '4000.00')
            ->assertJsonPath('donnees.lignes.0.type', 'service')
            ->assertJsonPath('donnees.lignes.0.service_id', $service->id)
            ->assertJsonPath('donnees.lignes.0.produit_id', null)
            ->assertJsonPath('donnees.lignes.0.mode_prix', null)
            ->assertJsonPath('donnees.lignes.0.nom_produit', 'Coupe homme');

        $this->assertSame(0, StockMovement::where('type', 'vente')->count());
    }

    public function test_products_and_services_can_be_mixed_in_one_sale(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = $this->stockedProduct($store, 1500);
        $service = Service::factory()->for($store)->create(['prix' => 3000]);

        $this->checkout($store, $register, [
            ['service_id' => $service->id, 'quantite' => 1],
            ['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 2],
        ])->assertStatus(201)->assertJsonPath('donnees.total', '6000.00')->assertJsonCount(2, 'donnees.lignes');

        $this->assertSame(48.0, (float) Stock::where('produit_id', $product->id)->value('quantite'));
    }

    public function test_a_line_must_carry_exactly_one_of_product_or_service(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = $this->stockedProduct($store);
        $service = Service::factory()->for($store)->create();

        $this->checkout($store, $register, [['produit_id' => $product->id, 'service_id' => $service->id, 'mode_prix' => 'detail', 'quantite' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('lignes.0', 'erreurs');

        $this->checkout($store, $register, [['quantite' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('lignes.0', 'erreurs');

        $this->checkout($store, $register, [['produit_id' => $product->id, 'quantite' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('lignes.0.mode_prix', 'erreurs');
    }

    public function test_a_service_of_another_store_is_rejected(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $owner = auth()->user();
        // createStoreWithFeatures() se connecte en tant que nouveau propriétaire :
        // on revient ensuite au propriétaire de la boutique testée.
        ['store' => $otherStore] = $this->createStoreWithFeatures(['services']);
        $foreign = Service::factory()->for($otherStore)->create();
        Sanctum::actingAs($owner);

        $this->checkout($store, $register, [['service_id' => $foreign->id, 'quantite' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('lignes.0.service_id', 'erreurs');
    }

    public function test_a_line_discount_reduces_the_line_and_combines_with_the_global_one(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $a = $this->stockedProduct($store, 1000);
        $b = $this->stockedProduct($store, 500);

        $this->checkout($store, $register, [
            ['produit_id' => $a->id, 'mode_prix' => 'detail', 'quantite' => 2, 'remise' => 300], // 2000 - 300 = 1700
            ['produit_id' => $b->id, 'mode_prix' => 'detail', 'quantite' => 1],                  // 500
        ], ['montant_remise' => 200])
            ->assertStatus(201)
            ->assertJsonPath('donnees.lignes.0.remise', '300.00')
            ->assertJsonPath('donnees.lignes.0.total', '1700.00')
            ->assertJsonPath('donnees.lignes.1.remise', '0.00')
            ->assertJsonPath('donnees.sous_total', '2200.00')
            ->assertJsonPath('donnees.remise', '200.00')
            ->assertJsonPath('donnees.total', '2000.00');
    }

    public function test_a_line_discount_cannot_exceed_the_line_amount(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = $this->stockedProduct($store, 600);

        $this->checkout($store, $register, [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1, 'remise' => 601]])
            ->assertStatus(422)->assertJsonPath('code', 'REMISE_INVALIDE');

        $this->assertSame(50.0, (float) Stock::where('produit_id', $product->id)->value('quantite'));
    }

    public function test_selling_a_product_whose_stock_was_never_initialized_is_a_clear_422(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = Product::factory()->for($store)->create(['nom' => 'Riz importé', 'vente_detail_active' => true, 'prix_detail' => 800]);

        $this->checkout($store, $register, [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'STOCK_NON_INITIALISE');
    }

    public function test_reports_best_products_ignore_service_lines(): void
    {
        ['store' => $store, 'register' => $register] = $this->readyStore();
        $product = $this->stockedProduct($store, 500);
        $service = Service::factory()->for($store)->create(['prix' => 9000]);

        $this->checkout($store, $register, [
            ['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1],
            ['service_id' => $service->id, 'quantite' => 1],
        ])->assertStatus(201);

        $top = $this->getJson("/api/boutiques/{$store->id}/rapports/tableau-de-bord")->assertStatus(200)->json('donnees.meilleurs_produits');

        $this->assertCount(1, $top);
        $this->assertSame($product->id, $top[0]['produit_id']);
    }

    public function test_a_store_without_the_stock_feature_sells_products_without_stock_and_cancels_cleanly(): void
    {
        // Ex. domaine « Autre » ou salon : produits vendus sans suivi de quantité.
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'services', 'caisse', 'ventes']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 2500]);

        $sale = $this->checkout($store, $register, [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 2]])
            ->assertStatus(201)->assertJsonPath('donnees.total', '5000.00')->json('donnees');

        $this->postJson("/api/boutiques/{$store->id}/ventes/{$sale['id']}/annuler", ['motif' => 'Erreur'])->assertStatus(200);

        $this->assertSame(0, StockMovement::count());
        $this->assertNull(Stock::where('produit_id', $product->id)->first());
    }
}

<?php

namespace Tests\Feature\Modules\Sales;

use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Vente au poids (boucherie) : quantité décimale × prix au kilogramme. */
class SaleByWeightTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{store: Store, register: CashRegister} */
    private function butcher(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock', 'caisse', 'ventes', 'clients']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);

        return ['store' => $store, 'register' => $register];
    }

    private function meat(Store $store, string $name, int $pricePerKg, float $stockKg = 30): Product
    {
        $product = Product::factory()->for($store)->create(['nom' => $name, 'unite' => 'kg', 'vente_detail_active' => true, 'prix_detail' => $pricePerKg, 'vente_gros_active' => false]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => $stockKg])->assertStatus(201);

        return $product;
    }

    public function test_two_and_a_half_kilos_at_3500_per_kilo_cost_8750(): void
    {
        ['store' => $store, 'register' => $register] = $this->butcher();
        $beef = $this->meat($store, 'Viande de bœuf', 3500);

        $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $beef->id, 'mode_prix' => 'detail', 'quantite' => 2.5]],
            'caisse_id' => $register->id,
        ])
            ->assertStatus(201)
            ->assertJsonPath('donnees.lignes.0.quantite', '2.500')
            ->assertJsonPath('donnees.lignes.0.prix_unitaire', '3500.00')
            ->assertJsonPath('donnees.total', '8750.00');

        $this->assertSame(27.5, (float) Stock::where('produit_id', $beef->id)->value('quantite'));
        $this->assertSame('8750.00', (string) CashMovement::where('type', 'vente')->value('montant'));
    }

    public function test_several_weighed_cuts_in_one_sale_with_gram_precision(): void
    {
        ['store' => $store, 'register' => $register] = $this->butcher();
        $beef = $this->meat($store, 'Entrecôte', 4200);
        $liver = $this->meat($store, 'Foie', 2800);

        // 0,375 kg × 4 200 = 1 575 ; 1,250 kg × 2 800 = 3 500 ; remise de ligne 75.
        $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [
                ['produit_id' => $beef->id, 'mode_prix' => 'detail', 'quantite' => 0.375, 'remise' => 75],
                ['produit_id' => $liver->id, 'mode_prix' => 'detail', 'quantite' => 1.25],
            ],
            'caisse_id' => $register->id,
        ])
            ->assertStatus(201)
            ->assertJsonPath('donnees.total', '5000.00');

        $this->assertSame(29.625, (float) Stock::where('produit_id', $beef->id)->value('quantite'));
        $this->assertSame(28.75, (float) Stock::where('produit_id', $liver->id)->value('quantite'));
    }

    public function test_a_weight_above_the_remaining_stock_is_refused(): void
    {
        ['store' => $store, 'register' => $register] = $this->butcher();
        $beef = $this->meat($store, 'Jarret', 3000, 1.2);

        $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $beef->id, 'mode_prix' => 'detail', 'quantite' => 1.25]],
            'caisse_id' => $register->id,
        ])->assertStatus(422)->assertJsonPath('code', 'STOCK_INSUFFISANT');

        $this->assertSame(1.2, (float) Stock::where('produit_id', $beef->id)->value('quantite'));
    }
}

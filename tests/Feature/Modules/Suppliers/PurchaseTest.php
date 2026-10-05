<?php

namespace Tests\Feature\Modules\Suppliers;

use App\Models\User;
use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Achats fournisseurs : stock, prix d'achat, règlements — docs/modules.md §Suppliers. */
class PurchaseTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{owner: User, store: Store, supplier: Supplier, register: CashRegister, product: Product} */
    private function setUpStore(array $features = ['produits', 'stock', 'caisse', 'ventes', 'fournisseurs'], float $opening = 50000): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures($features);
        Sanctum::actingAs($owner);

        $register = CashRegister::factory()->for($store)->create();
        if (in_array('caisse', $features, true)) {
            $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => $opening])->assertStatus(201);
        }

        return [
            'owner' => $owner,
            'store' => $store,
            'supplier' => Supplier::factory()->for($store)->create(),
            'register' => $register,
            'product' => Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 800, 'prix_achat' => 500]),
        ];
    }

    private function purchase(Store $store, array $payload)
    {
        return $this->postJson("/api/boutiques/{$store->id}/achats", $payload);
    }

    public function test_a_purchase_initializes_stock_of_a_never_stocked_product_and_updates_its_cost(): void
    {
        ['store' => $store, 'supplier' => $supplier, 'product' => $product] = $this->setUpStore();

        $this->purchase($store, [
            'fournisseur_id' => $supplier->id,
            'lignes' => [['produit_id' => $product->id, 'quantite' => 24, 'cout_unitaire' => 550]],
        ])
            ->assertStatus(201)
            ->assertJsonPath('donnees.montant_total', '13200.00')
            ->assertJsonPath('donnees.montant_paye', '0.00')
            ->assertJsonPath('donnees.reste_a_payer', '13200.00')
            ->assertJsonPath('donnees.statut_paiement', 'non_paye')
            ->assertJsonPath('donnees.fournisseur.nom', $supplier->nom);

        $this->assertSame(24.0, (float) Stock::where('produit_id', $product->id)->value('quantite'));
        $this->assertSame('550.00', (string) $product->fresh()->prix_achat);
    }

    public function test_a_second_purchase_adds_to_existing_stock_as_an_achat_movement(): void
    {
        ['store' => $store, 'product' => $product] = $this->setUpStore();
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 10])->assertStatus(201);

        $id = $this->purchase($store, ['lignes' => [['produit_id' => $product->id, 'quantite' => 5, 'cout_unitaire' => 500]]])
            ->assertStatus(201)->json('donnees.id');

        $this->assertSame(15.0, (float) Stock::where('produit_id', $product->id)->value('quantite'));
        $this->assertDatabaseHas('mouvements_stock', ['produit_id' => $product->id, 'type' => 'achat', 'reference_type' => 'achat', 'reference_id' => $id]);
    }

    public function test_the_purchase_price_update_can_be_turned_off(): void
    {
        ['store' => $store, 'product' => $product] = $this->setUpStore();

        $this->purchase($store, [
            'lignes' => [['produit_id' => $product->id, 'quantite' => 1, 'cout_unitaire' => 999]],
            'mettre_a_jour_prix_achat' => false,
        ])->assertStatus(201);

        $this->assertSame('500.00', (string) $product->fresh()->prix_achat);
    }

    public function test_paying_from_the_register_records_a_cash_out_referencing_the_purchase(): void
    {
        ['store' => $store, 'register' => $register, 'product' => $product] = $this->setUpStore();

        $id = $this->purchase($store, [
            'lignes' => [['produit_id' => $product->id, 'quantite' => 10, 'cout_unitaire' => 500]],
            'paiement' => ['montant' => 5000, 'mode' => 'caisse', 'caisse_id' => $register->id],
        ])
            ->assertStatus(201)
            ->assertJsonPath('donnees.statut_paiement', 'paye')
            ->assertJsonPath('donnees.paiements.0.mode', 'caisse')
            ->json('donnees.id');

        $movement = CashMovement::where('type', 'sortie')->firstOrFail();
        $this->assertSame('-5000.00', (string) $movement->montant);
        $this->assertSame('45000.00', (string) $movement->solde_apres);
        $this->assertSame('achat', $movement->reference_type);
        $this->assertSame($id, $movement->reference_id);
    }

    public function test_a_credit_purchase_can_be_settled_in_several_payments(): void
    {
        ['store' => $store, 'supplier' => $supplier, 'register' => $register, 'product' => $product] = $this->setUpStore();
        $id = $this->purchase($store, [
            'fournisseur_id' => $supplier->id,
            'lignes' => [['produit_id' => $product->id, 'quantite' => 20, 'cout_unitaire' => 500]], // 10 000
        ])->json('donnees.id');
        $url = "/api/boutiques/{$store->id}/achats/{$id}/paiements";

        $this->getJson("/api/boutiques/{$store->id}/fournisseurs/{$supplier->id}")->assertJsonPath('donnees.solde_du', '10000.00');

        $this->postJson($url, ['montant' => 4000, 'mode' => 'externe', 'note' => 'Virement Orange Money'])
            ->assertStatus(201)->assertJsonPath('donnees.statut_paiement', 'partiel')->assertJsonPath('donnees.reste_a_payer', '6000.00');
        $this->postJson($url, ['montant' => 6000, 'mode' => 'caisse', 'caisse_id' => $register->id])
            ->assertStatus(201)->assertJsonPath('donnees.statut_paiement', 'paye')->assertJsonCount(2, 'donnees.paiements');

        $this->getJson("/api/boutiques/{$store->id}/fournisseurs/{$supplier->id}")->assertJsonPath('donnees.solde_du', '0.00');
        $this->assertSame(1, CashMovement::where('type', 'sortie')->count()); // seul le règlement « caisse » sort de la caisse
    }

    public function test_cannot_pay_more_than_what_is_due(): void
    {
        ['store' => $store, 'product' => $product] = $this->setUpStore();
        $id = $this->purchase($store, ['lignes' => [['produit_id' => $product->id, 'quantite' => 1, 'cout_unitaire' => 1000]]])->json('donnees.id');

        $this->postJson("/api/boutiques/{$store->id}/achats/{$id}/paiements", ['montant' => 1001, 'mode' => 'externe'])
            ->assertStatus(422)->assertJsonPath('code', 'PAIEMENT_INVALIDE');
    }

    public function test_paying_from_a_closed_register_or_beyond_its_balance_rolls_everything_back(): void
    {
        ['store' => $store, 'register' => $register, 'product' => $product] = $this->setUpStore(opening: 1000);

        // Plus que le solde de la caisse : achat ET stock annulés.
        $this->purchase($store, [
            'lignes' => [['produit_id' => $product->id, 'quantite' => 10, 'cout_unitaire' => 500]],
            'paiement' => ['montant' => 5000, 'mode' => 'caisse', 'caisse_id' => $register->id],
        ])->assertStatus(422)->assertJsonPath('code', 'SOLDE_CAISSE_INSUFFISANT');
        $this->assertDatabaseCount('achats', 0);
        $this->assertNull(Stock::where('produit_id', $product->id)->first());

        $closed = CashRegister::factory()->for($store)->create();
        $this->purchase($store, [
            'lignes' => [['produit_id' => $product->id, 'quantite' => 1, 'cout_unitaire' => 100]],
            'paiement' => ['montant' => 100, 'mode' => 'caisse', 'caisse_id' => $closed->id],
        ])->assertStatus(422)->assertJsonPath('code', 'AUCUNE_SESSION_CAISSE_OUVERTE');
    }

    public function test_a_register_is_required_when_paying_from_the_register(): void
    {
        ['store' => $store, 'product' => $product] = $this->setUpStore();

        $this->purchase($store, [
            'lignes' => [['produit_id' => $product->id, 'quantite' => 1, 'cout_unitaire' => 100]],
            'paiement' => ['montant' => 100, 'mode' => 'caisse'],
        ])->assertStatus(422)->assertJsonValidationErrors('paiement.caisse_id', 'erreurs');
    }

    public function test_the_idempotency_key_prevents_a_double_purchase(): void
    {
        ['store' => $store, 'product' => $product] = $this->setUpStore();
        $payload = ['lignes' => [['produit_id' => $product->id, 'quantite' => 3, 'cout_unitaire' => 500]], 'cle_idempotence' => 'achat-123'];

        $first = $this->purchase($store, $payload)->assertStatus(201)->json('donnees.id');
        $second = $this->purchase($store, $payload)->assertStatus(200)->json('donnees.id');

        $this->assertSame($first, $second);
        $this->assertSame(3.0, (float) Stock::where('produit_id', $product->id)->value('quantite'));
    }

    public function test_a_store_without_stock_feature_records_the_purchase_without_stock(): void
    {
        ['store' => $store, 'product' => $product] = $this->setUpStore(['produits', 'caisse', 'ventes', 'fournisseurs']);

        $this->purchase($store, ['lignes' => [['produit_id' => $product->id, 'quantite' => 3, 'cout_unitaire' => 500]]])->assertStatus(201);

        $this->assertSame(0, StockMovement::count());
    }

    public function test_a_product_of_another_store_is_rejected_and_a_foreign_purchase_is_unreachable(): void
    {
        ['owner' => $owner, 'store' => $store, 'product' => $product] = $this->setUpStore();
        ['store' => $other] = $this->createStoreWithFeatures(['produits', 'fournisseurs']);
        $foreignProduct = Product::factory()->for($other)->create();
        Sanctum::actingAs($owner);

        $this->purchase($store, ['lignes' => [['produit_id' => $foreignProduct->id, 'quantite' => 1, 'cout_unitaire' => 100]]])
            ->assertStatus(422)->assertJsonValidationErrors('lignes.0.produit_id', 'erreurs');

        $id = $this->purchase($store, ['lignes' => [['produit_id' => $product->id, 'quantite' => 1, 'cout_unitaire' => 100]]])->json('donnees.id');
        ['proprietaire' => $intruder, 'store' => $intruderStore] = $this->createStoreWithFeatures(['fournisseurs']);
        Sanctum::actingAs($intruder);
        $this->getJson("/api/boutiques/{$intruderStore->id}/achats/{$id}")->assertStatus(404);
    }

    public function test_list_can_be_filtered_by_payment_status(): void
    {
        ['store' => $store, 'product' => $product] = $this->setUpStore();
        $this->purchase($store, ['lignes' => [['produit_id' => $product->id, 'quantite' => 1, 'cout_unitaire' => 100]], 'paiement' => ['montant' => 100, 'mode' => 'externe']]);
        $this->purchase($store, ['lignes' => [['produit_id' => $product->id, 'quantite' => 1, 'cout_unitaire' => 200]]]);

        $this->getJson("/api/boutiques/{$store->id}/achats?statut_paiement=non_solde")->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.montant_total', '200.00');
        $this->getJson("/api/boutiques/{$store->id}/achats?statut_paiement=paye")->assertJsonCount(1, 'donnees');
    }
}

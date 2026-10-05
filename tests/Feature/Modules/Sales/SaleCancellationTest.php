<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\CashRegister\Models\CashMovement;
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

/** Annulation totale d'une vente — docs/sales.md §20. */
class SaleCancellationTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{owner: User, store: Store, register: CashRegister, product: Product, sale: array} */
    private function soldOnce(float $opening = 0): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'services', 'stock', 'caisse', 'ventes']);
        Sanctum::actingAs($owner);

        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => $opening])->assertStatus(201);

        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 600, 'vente_gros_active' => false]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 10])->assertStatus(201);

        $sale = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 3]],
            'caisse_id' => $register->id,
        ])->assertStatus(201)->json('donnees');

        return ['owner' => $owner, 'store' => $store, 'register' => $register, 'product' => $product, 'sale' => $sale];
    }

    public function test_cancelling_restores_stock_refunds_cash_and_keeps_the_sale(): void
    {
        ['store' => $store, 'register' => $register, 'product' => $product, 'sale' => $sale] = $this->soldOnce();
        $this->assertSame(7.0, (float) Stock::where('produit_id', $product->id)->value('quantite'));

        $this->postJson("/api/boutiques/{$store->id}/ventes/{$sale['id']}/annuler", ['motif' => 'Client a changé d\'avis'])
            ->assertStatus(200)
            ->assertJsonPath('donnees.statut', 'annulee')
            ->assertJsonPath('donnees.annulation.motif', 'Client a changé d\'avis')
            ->assertJsonPath('donnees.reference', $sale['reference']);

        $this->assertSame(10.0, (float) Stock::where('produit_id', $product->id)->value('quantite'));
        $this->assertDatabaseHas('mouvements_stock', ['produit_id' => $product->id, 'type' => 'retour', 'reference_id' => $sale['id']]);

        $refund = CashMovement::where('type', 'remboursement')->firstOrFail();
        $this->assertSame('-1800.00', (string) $refund->montant);
        $this->assertSame('0.00', (string) $refund->solde_apres);
        $this->assertSame($register->session_ouverte_id ?? $register->fresh()->session_ouverte_id, $refund->session_caisse_id);
    }

    public function test_a_sale_cannot_be_cancelled_twice(): void
    {
        ['store' => $store, 'product' => $product, 'sale' => $sale] = $this->soldOnce();
        $url = "/api/boutiques/{$store->id}/ventes/{$sale['id']}/annuler";

        $this->postJson($url, ['motif' => 'Erreur'])->assertStatus(200);
        $this->postJson($url, ['motif' => 'Erreur'])->assertStatus(422)->assertJsonPath('code', 'VENTE_DEJA_ANNULEE');

        // Pas de double remise en stock ni de double remboursement.
        $this->assertSame(10.0, (float) Stock::where('produit_id', $product->id)->value('quantite'));
        $this->assertSame(1, CashMovement::where('type', 'remboursement')->count());
    }

    public function test_a_reason_is_required(): void
    {
        ['store' => $store, 'sale' => $sale] = $this->soldOnce();

        $this->postJson("/api/boutiques/{$store->id}/ventes/{$sale['id']}/annuler", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motif', 'erreurs');
    }

    public function test_refund_needs_an_open_session_and_can_use_another_register(): void
    {
        ['store' => $store, 'register' => $register, 'sale' => $sale] = $this->soldOnce();
        $session = $register->fresh()->session_ouverte_id;
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$session}/fermer", ['montant_fermeture_reel' => 1800])->assertStatus(200);

        $url = "/api/boutiques/{$store->id}/ventes/{$sale['id']}/annuler";
        $this->postJson($url, ['motif' => 'Retour'])->assertStatus(422)->assertJsonPath('code', 'AUCUNE_SESSION_CAISSE_OUVERTE');

        $other = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$other->id}/sessions", ['montant_ouverture' => 5000])->assertStatus(201);

        $this->postJson($url, ['motif' => 'Retour', 'caisse_id' => $other->id])->assertStatus(200);
        $this->assertSame($other->fresh()->session_ouverte_id, CashMovement::where('type', 'remboursement')->value('session_caisse_id'));
    }

    public function test_cannot_refund_more_cash_than_the_drawer_holds(): void
    {
        ['store' => $store, 'register' => $register, 'product' => $product, 'sale' => $sale] = $this->soldOnce();
        $session = $register->fresh()->session_ouverte_id;
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions/{$session}/sortie", ['montant' => 1000])->assertStatus(201);

        $this->postJson("/api/boutiques/{$store->id}/ventes/{$sale['id']}/annuler", ['motif' => 'Retour'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SOLDE_CAISSE_INSUFFISANT');

        // Transaction annulée en entier : ni stock rendu, ni statut changé.
        $this->assertSame(7.0, (float) Stock::where('produit_id', $product->id)->value('quantite'));
        $this->assertSame(0, StockMovement::where('type', 'retour')->count());
        $this->getJson("/api/boutiques/{$store->id}/ventes/{$sale['id']}")->assertJsonPath('donnees.statut', 'terminee');
    }

    public function test_a_cashier_cannot_cancel_but_a_manager_can(): void
    {
        ['owner' => $owner, 'store' => $store, 'sale' => $sale] = $this->soldOnce();
        $cashier = User::factory()->create();
        $manager = User::factory()->create();
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $manager->email, 'role' => 'gerant'])->assertStatus(201);
        $url = "/api/boutiques/{$store->id}/ventes/{$sale['id']}/annuler";

        Sanctum::actingAs($cashier);
        $this->postJson($url, ['motif' => 'Retour'])->assertStatus(403);

        Sanctum::actingAs($manager);
        $this->postJson($url, ['motif' => 'Retour'])->assertStatus(200);
    }

    public function test_a_sale_of_another_store_cannot_be_cancelled(): void
    {
        ['sale' => $sale] = $this->soldOnce();
        ['proprietaire' => $otherOwner, 'store' => $otherStore] = $this->createStoreWithFeatures(['produits', 'stock', 'caisse', 'ventes']);
        Sanctum::actingAs($otherOwner);

        $this->postJson("/api/boutiques/{$otherStore->id}/ventes/{$sale['id']}/annuler", ['motif' => 'Retour'])->assertStatus(404);
    }

    public function test_cancelling_a_service_sale_refunds_without_touching_stock(): void
    {
        ['store' => $store, 'register' => $register] = $this->soldOnce();
        $service = Service::factory()->for($store)->create(['prix' => 2500]);
        $before = StockMovement::count();

        $sale = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['service_id' => $service->id, 'quantite' => 1]],
            'caisse_id' => $register->id,
        ])->assertStatus(201)->json('donnees');

        $this->postJson("/api/boutiques/{$store->id}/ventes/{$sale['id']}/annuler", ['motif' => 'Prestation non réalisée'])->assertStatus(200);

        $this->assertSame($before, StockMovement::count());
        $this->assertSame('-2500.00', (string) CashMovement::where('type', 'remboursement')->value('montant'));
    }

    public function test_reports_ignore_cancelled_sales(): void
    {
        ['store' => $store, 'sale' => $sale] = $this->soldOnce();
        $this->postJson("/api/boutiques/{$store->id}/ventes/{$sale['id']}/annuler", ['motif' => 'Erreur'])->assertStatus(200);

        $this->getJson("/api/boutiques/{$store->id}/ventes?statut=terminee")->assertJsonCount(0, 'donnees');
        $this->getJson("/api/boutiques/{$store->id}/ventes?statut=annulee")->assertJsonCount(1, 'donnees');
    }
}

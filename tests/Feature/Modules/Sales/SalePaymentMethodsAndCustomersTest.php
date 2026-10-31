<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Moyens de paiement (seules les espèces entrent en caisse) et client créé à la vente. */
class SalePaymentMethodsAndCustomersTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{owner: User, store: Store, register: CashRegister, product: Product} */
    private function shop(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'ventes', 'caisse', 'clients']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 50000, 'vente_gros_active' => false]);

        return ['owner' => $owner, 'store' => $store, 'register' => $register, 'product' => $product];
    }

    private function sell(array $s, array $extra = [])
    {
        return $this->postJson("/api/boutiques/{$s['store']->id}/ventes/encaisser", [
            'caisse_id' => $s['register']->id,
            'lignes' => [['produit_id' => $s['product']->id, 'mode_prix' => 'detail', 'quantite' => 1]],
            ...$extra,
        ]);
    }

    public function test_mobile_money_card_and_transfer_do_not_enter_the_register(): void
    {
        $s = $this->shop();

        foreach (['mobile_money', 'carte', 'virement'] as $method) {
            $this->sell($s, ['mode_paiement' => $method])->assertStatus(201)->assertJsonPath('donnees.mode_paiement', $method);
        }
        $this->assertSame(0, CashMovement::where('type', 'vente')->count());

        $cash = $this->sell($s, ['mode_paiement' => 'especes'])->assertStatus(201)->json('donnees.id');
        $this->assertSame('50000.00', (string) CashMovement::where('type', 'vente')->value('montant'));

        // Annuler une vente Mobile Money ne sort rien de la caisse ; une vente en espèces, si.
        $mobile = $this->sell($s, ['mode_paiement' => 'mobile_money'])->json('donnees.id');
        $this->postJson("/api/boutiques/{$s['store']->id}/ventes/{$mobile}/annuler", ['motif' => 'Erreur'])->assertStatus(200);
        $this->assertSame(0, CashMovement::where('type', 'remboursement')->count());
        $this->postJson("/api/boutiques/{$s['store']->id}/ventes/{$cash}/annuler", ['motif' => 'Erreur'])->assertStatus(200);
        $this->assertSame('-50000.00', (string) CashMovement::where('type', 'remboursement')->value('montant'));
    }

    public function test_a_credit_customer_is_created_on_the_fly_and_found_again_by_phone(): void
    {
        $s = $this->shop();

        $this->sell($s, ['mode_paiement' => 'credit', 'acompte' => 0])->assertStatus(422)->assertJsonStructure(['erreurs' => ['client_id']]);

        $this->sell($s, ['mode_paiement' => 'credit', 'acompte' => 10000, 'nouveau_client' => ['nom' => 'Aminata Diallo', 'telephone' => '77 123 45 67']])
            ->assertStatus(201)->assertJsonPath('donnees.client.nom', 'Aminata Diallo')->assertJsonPath('donnees.montant_credit', '40000.00');
        $customer = Customer::firstOrFail();
        $this->getJson("/api/boutiques/{$s['store']->id}/clients/{$customer->id}")->assertJsonPath('donnees.solde', '40000.00');

        // Même téléphone : même cliente, la dette s'additionne.
        $this->sell($s, ['mode_paiement' => 'credit', 'acompte' => 0, 'nouveau_client' => ['nom' => 'Aminata', 'telephone' => '77 123 45 67']])->assertStatus(201);
        $this->assertSame(1, Customer::count());
        $this->getJson("/api/boutiques/{$s['store']->id}/clients/{$customer->id}")->assertJsonPath('donnees.solde', '90000.00');

        // L'historique d'achats du client et des ventes d'un vendeur.
        $this->sell($s);
        $this->getJson("/api/boutiques/{$s['store']->id}/ventes?client_id={$customer->id}")->assertJsonCount(2, 'donnees');
        $this->getJson("/api/boutiques/{$s['store']->id}/ventes?vendeur_id={$s['owner']->id}")->assertJsonCount(3, 'donnees');
    }
}

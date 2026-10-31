<?php

namespace Tests\Feature\Modules\Returns;

use App\Models\User;
use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Devices\Models\Device;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Warranties\Models\Warranty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Retours et échanges rattachés à la vente d'origine — docs/modules.md §Returns. */
class ReturnTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{owner: User, store: Store, register: CashRegister, phone: Product, charger: Product, customer: Customer, sale: array} */
    private function soldShop(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock', 'ventes', 'caisse', 'clients', 'appareils', 'garanties', 'retours']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 200000])->assertStatus(201);
        $phone = Product::factory()->for($store)->create(['nom' => 'Galaxy A15', 'suivi_unitaire' => true, 'garantie_mois' => 12, 'prix_achat' => 90000, 'vente_detail_active' => true, 'prix_detail' => 120000, 'vente_gros_active' => false]);
        $charger = Product::factory()->for($store)->create(['nom' => 'Chargeur', 'prix_achat' => 2000, 'vente_detail_active' => true, 'prix_detail' => 5000, 'vente_gros_active' => false]);
        $this->postJson("/api/boutiques/{$store->id}/appareils", ['produit_id' => $phone->id, 'identifiants' => ['356938035643809'], 'ajouter_au_stock' => true])->assertStatus(201);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$charger->id}/mouvements", ['type' => 'initial', 'quantite' => 10])->assertStatus(201);
        $customer = Customer::factory()->for($store)->create();

        // 120 000 + 2 × 5 000 = 130 000, remise globale 1 000 → 129 000.
        $sale = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'caisse_id' => $register->id, 'client_id' => $customer->id, 'montant_remise' => 1000,
            'lignes' => [
                ['produit_id' => $phone->id, 'mode_prix' => 'detail', 'quantite' => 1, 'appareil_ids' => [Device::firstOrFail()->id]],
                ['produit_id' => $charger->id, 'mode_prix' => 'detail', 'quantite' => 2],
            ],
        ])->assertStatus(201)->json('donnees');

        return ['owner' => $owner, 'store' => $store, 'register' => $register, 'phone' => $phone, 'charger' => $charger, 'customer' => $customer, 'sale' => $sale];
    }

    private function lineId(array $s, int $productId): int
    {
        return collect($s['sale']['lignes'])->firstWhere('produit_id', $productId)['id'];
    }

    private function return(array $s, array $data)
    {
        return $this->postJson("/api/boutiques/{$s['store']->id}/retours", ['vente_id' => $s['sale']['id'], 'motif' => 'Le client a changé d’avis', ...$data]);
    }

    public function test_a_resellable_accessory_is_refunded_in_cash_with_the_discount_share(): void
    {
        $s = $this->soldShop();
        $this->return($s, [
            'lignes' => [['ligne_vente_id' => $this->lineId($s, $s['charger']->id), 'quantite' => 1]],
            'decision' => 'remboursement', 'etat_produits' => 'revendable', 'mode_remboursement' => 'especes',
        ])->assertStatus(201)->assertJsonPath('donnees.montant', '4961.54');

        $this->assertSame(9.0, (float) Stock::where('produit_id', $s['charger']->id)->value('quantite'));
        $this->assertSame('-4961.54', (string) CashMovement::where('type', 'remboursement')->value('montant'));
        $this->getJson("/api/boutiques/{$s['store']->id}/ventes/{$s['sale']['id']}")->assertJsonPath('donnees.lignes.1.quantite_retournee', '1.000');

        // On ne retourne pas plus que vendu.
        $this->return($s, [
            'lignes' => [['ligne_vente_id' => $this->lineId($s, $s['charger']->id), 'quantite' => 2]],
            'decision' => 'remboursement', 'etat_produits' => 'revendable', 'mode_remboursement' => 'hors_caisse',
        ])->assertStatus(422)->assertJsonPath('code', 'QUANTITE_RETOUR_INVALIDE');
    }

    public function test_a_defective_phone_returned_for_store_credit_is_set_aside(): void
    {
        $s = $this->soldShop();
        $device = Device::firstOrFail();
        $line = $this->lineId($s, $s['phone']->id);

        $this->return($s, ['lignes' => [['ligne_vente_id' => $line, 'quantite' => 1]], 'decision' => 'avoir', 'etat_produits' => 'defectueux'])
            ->assertStatus(422)->assertJsonPath('code', 'APPAREILS_REQUIS');
        $this->return($s, ['lignes' => [['ligne_vente_id' => $line, 'quantite' => 1, 'appareil_ids' => [$device->id]]], 'decision' => 'avoir', 'etat_produits' => 'defectueux'])
            ->assertStatus(201)->assertJsonPath('donnees.montant', '119076.92');

        // Défectueux : tracé, mais pas revendable (stock inchangé), garantie annulée, avoir au compte.
        $this->assertSame('defectueux', $device->refresh()->statut->value);
        $this->assertSame(0.0, (float) Stock::where('produit_id', $s['phone']->id)->value('quantite'));
        $this->assertSame(1, StockMovement::where('type', 'perte')->count());
        $this->assertSame('annulee', Warranty::whereNotNull('appareil_id')->firstOrFail()->statut);
        $this->getJson("/api/boutiques/{$s['store']->id}/clients/{$s['customer']->id}")->assertJsonPath('donnees.solde', '-119076.92');
        $this->assertSame(0, CashMovement::where('type', 'remboursement')->count());
    }

    public function test_guards_and_rights(): void
    {
        $s = $this->soldShop();
        $cashier = User::factory()->create();
        $this->postJson("/api/boutiques/{$s['store']->id}/membres", ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);
        Sanctum::actingAs($cashier);
        $this->return($s, ['lignes' => [['ligne_vente_id' => $this->lineId($s, $s['charger']->id), 'quantite' => 1]], 'decision' => 'remboursement', 'etat_produits' => 'revendable'])->assertStatus(403);

        Sanctum::actingAs($s['owner']);
        $this->postJson("/api/boutiques/{$s['store']->id}/ventes/{$s['sale']['id']}/annuler", ['motif' => 'Erreur'])->assertStatus(200);
        $this->return($s, ['lignes' => [['ligne_vente_id' => $this->lineId($s, $s['charger']->id), 'quantite' => 1]], 'decision' => 'remboursement', 'etat_produits' => 'revendable'])
            ->assertStatus(422)->assertJsonPath('code', 'VENTE_ANNULEE');
    }
}

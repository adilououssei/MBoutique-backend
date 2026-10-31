<?php

namespace Tests\Feature\Modules\Warranties;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Devices\Models\Device;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Warranties\Models\Warranty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Garanties créées à la vente et réclamations — docs/modules.md §Warranties. */
class WarrantyTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{owner: User, store: Store, register: CashRegister, phone: Product, charger: Product, customer: Customer} */
    private function shop(array $features = ['produits', 'stock', 'ventes', 'caisse', 'clients', 'appareils', 'garanties']): array
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures($features);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);
        $phone = Product::factory()->for($store)->create(['nom' => 'Galaxy A15', 'suivi_unitaire' => true, 'garantie_mois' => 12, 'conditions_garantie' => 'Hors casse et oxydation', 'vente_detail_active' => true, 'prix_detail' => 120000, 'vente_gros_active' => false]);
        $charger = Product::factory()->for($store)->create(['nom' => 'Chargeur 20 W', 'garantie_mois' => 6, 'vente_detail_active' => true, 'prix_detail' => 5000, 'vente_gros_active' => false]);
        $this->postJson("/api/boutiques/{$store->id}/appareils", ['produit_id' => $phone->id, 'identifiants' => ['356938035643809'], 'ajouter_au_stock' => true]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$charger->id}/mouvements", ['type' => 'initial', 'quantite' => 10])->assertStatus(201);
        $customer = Customer::factory()->for($store)->create(['nom' => 'Fatou']);

        return ['owner' => $owner, 'store' => $store, 'register' => $register, 'phone' => $phone, 'charger' => $charger, 'customer' => $customer];
    }

    private function sellBoth(array $s): int
    {
        $device = Device::where('produit_id', $s['phone']->id)->firstOrFail();

        return $this->postJson("/api/boutiques/{$s['store']->id}/ventes/encaisser", [
            'caisse_id' => $s['register']->id, 'client_id' => $s['customer']->id,
            'lignes' => [
                ['produit_id' => $s['phone']->id, 'mode_prix' => 'detail', 'quantite' => 1, 'appareil_ids' => [$device->id]],
                ['produit_id' => $s['charger']->id, 'mode_prix' => 'detail', 'quantite' => 2],
            ],
        ])->assertStatus(201)->json('donnees.id');
    }

    public function test_warranties_are_created_at_sale_found_by_imei_and_claims_are_followed(): void
    {
        $s = $this->shop();
        $saleId = $this->sellBoth($s);
        $url = "/api/boutiques/{$s['store']->id}/garanties";

        $this->assertSame(2, Warranty::count());
        $phone = Warranty::whereNotNull('appareil_id')->firstOrFail();
        $this->assertSame('2027-11-08', $phone->fin_le->toDateString());
        $this->assertSame('Hors casse et oxydation', $phone->conditions);
        $this->assertSame('2027-05-08', Warranty::whereNull('appareil_id')->firstOrFail()->fin_le->toDateString());
        $this->getJson("/api/boutiques/{$s['store']->id}/ventes/{$saleId}")->assertJsonPath('donnees.lignes.0.garanties.0.fin_le', '2027-11-08');

        $this->getJson("{$url}?recherche=643809")->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.client.nom', 'Fatou')->assertJsonPath('donnees.0.etat', 'active');
        $this->getJson("{$url}?vente_id={$saleId}")->assertJsonCount(2, 'donnees');
        $this->getJson("{$url}?recherche=Fatou")->assertJsonCount(2, 'donnees');

        $claim = $this->postJson("{$url}/{$phone->id}/reclamations", ['description' => 'Ne charge plus'])
            ->assertStatus(201)->assertJsonPath('donnees.reclamations.0.statut', 'ouverte')->json('donnees.reclamations.0.id');
        $this->getJson("{$url}?etat=reclamation")->assertJsonCount(1, 'donnees');
        $this->putJson("{$url}/{$phone->id}/reclamations/{$claim}", ['statut' => 'en_cours', 'note' => 'Envoyé au SAV'])->assertStatus(200);
        $this->putJson("{$url}/{$phone->id}/reclamations/{$claim}", ['statut' => 'resolue', 'resolution' => 'Connecteur remplacé'])
            ->assertStatus(200)->assertJsonPath('donnees.reclamations.0.resolution', 'Connecteur remplacé')->assertJsonCount(3, 'donnees.reclamations.0.suivi');
        $this->putJson("{$url}/{$phone->id}/reclamations/{$claim}", ['statut' => 'en_cours'])->assertStatus(422)->assertJsonPath('code', 'RECLAMATION_CLOSE');

        // Treize mois plus tard : expirées, plus de réclamation possible.
        Carbon::setTestNow('2027-12-10 10:00:00');
        $this->getJson("{$url}?etat=expiree")->assertJsonCount(2, 'donnees');
        $this->postJson("{$url}/{$phone->id}/reclamations", ['description' => 'Écran'])->assertStatus(422)->assertJsonPath('code', 'GARANTIE_EXPIREE');
    }

    public function test_cancelling_the_sale_cancels_its_warranties(): void
    {
        $s = $this->shop();
        $saleId = $this->sellBoth($s);
        $this->postJson("/api/boutiques/{$s['store']->id}/ventes/{$saleId}/annuler", ['motif' => 'Erreur'])->assertStatus(200);

        $this->assertSame(2, Warranty::where('statut', 'annulee')->count());
        $this->postJson("/api/boutiques/{$s['store']->id}/garanties/".Warranty::firstOrFail()->id.'/reclamations', ['description' => 'X'])
            ->assertStatus(422)->assertJsonPath('code', 'GARANTIE_ANNULEE');
    }

    public function test_no_warranty_without_the_feature_and_rights_are_respected(): void
    {
        $s = $this->shop(['produits', 'stock', 'ventes', 'caisse', 'clients']);
        $this->postJson("/api/boutiques/{$s['store']->id}/ventes/encaisser", ['caisse_id' => $s['register']->id, 'lignes' => [['produit_id' => $s['charger']->id, 'mode_prix' => 'detail', 'quantite' => 1]]])->assertStatus(201);
        $this->assertSame(0, Warranty::count());
        $this->getJson("/api/boutiques/{$s['store']->id}/garanties")->assertStatus(403);

        $t = $this->shop();
        $this->sellBoth($t);
        $employee = User::factory()->create();
        $this->postJson("/api/boutiques/{$t['store']->id}/membres", ['email' => $employee->email, 'role' => 'employe'])->assertStatus(201);
        Sanctum::actingAs($employee);
        $id = Warranty::query()->withoutStoreScope()->where('boutique_id', $t['store']->id)->firstOrFail()->id;
        $this->getJson("/api/boutiques/{$t['store']->id}/garanties/{$id}")->assertStatus(200);
        $this->postJson("/api/boutiques/{$t['store']->id}/garanties/{$id}/reclamations", ['description' => 'X'])->assertStatus(403);
    }
}

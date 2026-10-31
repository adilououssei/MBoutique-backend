<?php

namespace Tests\Feature\Modules\Repairs;

use App\Models\User;
use App\Modules\Admin\Support\DomainFeatureAssignment;
use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Fonctionnalités en option (Paramètres du module) et réparations — docs/modules.md §Repairs. */
class RepairTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** Boutique d'électronique : réparations proposées en option par le métier. @return array{owner: User, store: Store, register: CashRegister} */
    private function shop(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock', 'ventes', 'caisse', 'clients', 'employes']);
        $repairs = Feature::firstOrCreate(['slug' => 'reparations'], ['nom' => 'Réparations']);
        DomainFeature::create(['domaine_activite_id' => $store->domaine_activite_id, 'fonctionnalite_id' => $repairs->id, 'active_par_defaut' => false, 'optionnelle' => true]);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);

        return ['owner' => $owner, 'store' => $store, 'register' => $register];
    }

    private function enabled(Store $store): bool
    {
        return collect($this->getJson("/api/boutiques/{$store->id}/fonctionnalites")->json('donnees.fonctionnalites'))->firstWhere('slug', 'reparations')['activee'];
    }

    public function test_the_owner_turns_an_optional_feature_on_and_the_admin_can_withdraw_it(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->shop();
        $url = "/api/boutiques/{$store->id}/parametres/fonctionnalites";

        $this->assertFalse($this->enabled($store));
        $this->getJson("/api/boutiques/{$store->id}/reparations")->assertStatus(403);
        $this->getJson($url)->assertStatus(200)->assertJsonPath('donnees.0.slug', 'reparations')->assertJsonPath('donnees.0.activee', false);

        $this->putJson($url, ['fonctionnalites' => ['ventes' => false]])->assertStatus(422)->assertJsonPath('code', 'OPTION_INDISPONIBLE');
        $this->putJson($url, ['fonctionnalites' => ['reparations' => true]])->assertStatus(200)->assertJsonPath('donnees.0.activee', true);
        $this->assertTrue($this->enabled($store));
        $this->getJson("/api/boutiques/{$store->id}/reparations")->assertStatus(200);

        $cashier = User::factory()->create();
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);
        Sanctum::actingAs($cashier);
        $this->putJson($url, ['fonctionnalites' => ['reparations' => false]])->assertStatus(403);

        // L'admin retire l'option au métier : le choix de la boutique disparaît avec.
        $included = DomainFeature::where('domaine_activite_id', $store->domaine_activite_id)->where('active_par_defaut', true)->pluck('fonctionnalite_id')->all();
        (new DomainFeatureAssignment)->syncDomain($store->domaine_activite_id, $included, []);
        Sanctum::actingAs($owner);
        $this->assertFalse($this->enabled($store));
    }

    public function test_a_repair_goes_from_drop_off_to_delivery_with_parts_and_payments(): void
    {
        ['store' => $store, 'register' => $register] = $this->shop();
        $this->putJson("/api/boutiques/{$store->id}/parametres/fonctionnalites", ['fonctionnalites' => ['reparations' => true]])->assertStatus(200);
        $url = "/api/boutiques/{$store->id}/reparations";
        $screen = Product::factory()->for($store)->create(['nom' => 'Écran iPhone 11', 'vente_detail_active' => true, 'prix_detail' => 25000, 'vente_gros_active' => false]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$screen->id}/mouvements", ['type' => 'initial', 'quantite' => 3])->assertStatus(201);
        $technician = $this->postJson("/api/boutiques/{$store->id}/employes", ['nom' => 'Ibrahima', 'poste' => 'Technicien'])->json('donnees.id');

        $repair = $this->postJson($url, [
            'appareil' => 'iPhone 11 noir', 'marque' => 'Apple', 'modele' => 'iPhone 11', 'imei' => '356938035643809',
            'panne' => 'Écran cassé', 'etat_reception' => 'Rayures au dos', 'cout_estime' => 30000, 'technicien_id' => $technician,
            'prevu_le' => now()->addDays(2)->toDateString(), 'nouveau_client' => ['nom' => 'Moussa', 'telephone' => '770000000'],
        ])->assertStatus(201)->assertJsonPath('donnees.statut', 'recu')->assertJsonPath('donnees.technicien.nom', 'Ibrahima')->json('donnees');
        $this->assertSame('Moussa', Customer::firstOrFail()->nom);
        $id = $repair['id'];

        // Pièce : sort du stock ; retirée : y revient.
        $part = $this->postJson("{$url}/{$id}/pieces", ['produit_id' => $screen->id, 'quantite' => 1])->assertStatus(201)->json('donnees.pieces.0.id');
        $this->assertSame(2.0, (float) Stock::where('produit_id', $screen->id)->value('quantite'));
        $this->deleteJson("{$url}/{$id}/pieces/{$part}")->assertStatus(200);
        $this->assertSame(3.0, (float) Stock::where('produit_id', $screen->id)->value('quantite'));
        $this->postJson("{$url}/{$id}/pieces", ['produit_id' => $screen->id, 'quantite' => 1])->assertStatus(201);

        foreach (['diagnostic', 'en_reparation', 'repare'] as $status) {
            $this->postJson("{$url}/{$id}/statut", ['statut' => $status])->assertStatus(200);
        }
        $this->putJson("{$url}/{$id}", ['cout_final' => 32000, 'diagnostic' => 'Dalle remplacée'])->assertStatus(200)->assertJsonPath('donnees.reste_a_payer', '32000.00');

        // Acompte en espèces (en caisse), solde par Mobile Money (hors caisse).
        $this->postJson("{$url}/{$id}/paiements", ['montant' => 10000, 'mode' => 'especes', 'caisse_id' => $register->id])->assertStatus(201);
        $this->assertSame('10000.00', (string) CashMovement::where('type', 'entree')->value('montant'));
        $this->postJson("{$url}/{$id}/paiements", ['montant' => 30000, 'mode' => 'mobile_money'])->assertStatus(422)->assertJsonPath('code', 'PAIEMENT_INVALIDE');
        $this->postJson("{$url}/{$id}/statut", ['statut' => 'livre'])->assertStatus(422)->assertJsonPath('code', 'PAIEMENT_INCOMPLET');
        $this->postJson("{$url}/{$id}/paiements", ['montant' => 22000, 'mode' => 'mobile_money'])->assertStatus(201)->assertJsonPath('donnees.reste_a_payer', '0.00');
        $this->assertSame(1, CashMovement::where('type', 'entree')->count());
        $this->postJson("{$url}/{$id}/statut", ['statut' => 'livre'])->assertStatus(200)->assertJsonPath('donnees.statut', 'livre');

        $this->postJson("{$url}/{$id}/pieces", ['produit_id' => $screen->id, 'quantite' => 1])->assertStatus(422)->assertJsonPath('code', 'REPARATION_CLOSE');
        $this->getJson("{$url}?recherche=643809")->assertJsonCount(1, 'donnees');
        $this->getJson("{$url}/{$id}")->assertJsonCount(5, 'donnees.suivi');

        // Une réparation déjà payée ne s'annule pas sans remboursement.
        $other = $this->postJson($url, ['appareil' => 'Tecno Spark', 'panne' => 'Ne s’allume plus', 'cout_estime' => 5000])->json('donnees.id');
        $this->postJson("{$url}/{$other}/paiements", ['montant' => 2000, 'mode' => 'mobile_money'])->assertStatus(201);
        $this->postJson("{$url}/{$other}/statut", ['statut' => 'annule'])->assertStatus(422)->assertJsonPath('code', 'REPARATION_PAYEE');
    }
}

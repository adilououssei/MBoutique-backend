<?php

namespace Tests\Feature\Modules\Devices;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Devices\Models\Device;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Électronique : fiche technique et appareils suivis par IMEI / n° de série — docs/modules.md §Devices. */
class DeviceTrackingTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private const IMEI_A = '356938035643809';

    private const IMEI_B = '356938035643817';

    /** @return array{owner: User, store: Store, register: CashRegister, phone: Product, cable: Product, supplier: int} */
    private function shop(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'categories', 'stock', 'ventes', 'caisse', 'clients', 'fournisseurs', 'marques_modeles', 'appareils']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);
        $supplier = $this->postJson("/api/boutiques/{$store->id}/fournisseurs", ['nom' => 'Importateur Electro Plus'])->json('donnees.id');

        $phone = Product::findOrFail($this->postJson("/api/boutiques/{$store->id}/produits", [
            'nom' => 'iPhone 13 128 Go', 'marque' => 'Apple', 'modele' => 'iPhone 13', 'etat' => 'reconditionne',
            'caracteristiques' => '128 Go, écran 6,1"', 'fournisseur_id' => $supplier, 'suivi_unitaire' => true,
            'vente_detail_active' => true, 'prix_detail' => 350000,
        ])->assertStatus(201)
            ->assertJsonPath('donnees.marque', 'Apple')
            ->assertJsonPath('donnees.etat', 'reconditionne')
            ->assertJsonPath('donnees.fournisseur.nom', 'Importateur Electro Plus')
            ->assertJsonPath('donnees.suivi_unitaire', true)
            ->json('donnees.id'));
        $cable = Product::factory()->for($store)->create(['nom' => 'Câble USB-C', 'marque' => 'Anker', 'vente_detail_active' => true, 'prix_detail' => 2500, 'vente_gros_active' => false]);

        return ['owner' => $owner, 'store' => $store, 'register' => $register, 'phone' => $phone, 'cable' => $cable, 'supplier' => $supplier];
    }

    private function receivePhones(array $s, array $identifiers): void
    {
        $this->postJson("/api/boutiques/{$s['store']->id}/achats", [
            'fournisseur_id' => $s['supplier'],
            'lignes' => [['produit_id' => $s['phone']->id, 'quantite' => count($identifiers), 'cout_unitaire' => 280000, 'appareils' => $identifiers]],
        ])->assertStatus(201);
    }

    private function sell(array $s, array $line, array $extra = []): TestResponse
    {
        return $this->postJson("/api/boutiques/{$s['store']->id}/ventes/encaisser", [
            'caisse_id' => $s['register']->id,
            'lignes' => [['mode_prix' => 'detail', ...$line]],
            ...$extra,
        ]);
    }

    public function test_technical_fields_brands_and_filters(): void
    {
        $s = $this->shop();
        $base = "/api/boutiques/{$s['store']->id}/produits";

        $this->getJson("{$base}/marques")->assertStatus(200)
            ->assertJsonPath('donnees.0.marque', 'Anker')
            ->assertJsonPath('donnees.1.marque', 'Apple')
            ->assertJsonPath('donnees.1.modeles.0', 'iPhone 13');
        $this->getJson("{$base}?marque=Apple")->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.modele', 'iPhone 13');
        $this->getJson("{$base}?suivi_unitaire=0")->assertJsonCount(1, 'donnees');
        // Rien n'est imposé à un accessoire : un produit sans marque ni modèle reste valide.
        $this->postJson($base, ['nom' => 'Coque silicone', 'vente_detail_active' => true, 'prix_detail' => 1500])->assertStatus(201);
        $this->postJson($base, ['nom' => 'X', 'etat' => 'casse'])->assertStatus(422);
    }

    public function test_a_purchase_registers_each_phone_and_a_sale_takes_the_exact_one(): void
    {
        $s = $this->shop();
        $this->receivePhones($s, [self::IMEI_A, ' 356938035643817 ']);

        $devices = Device::orderBy('id')->get();
        $this->assertCount(2, $devices);
        $this->assertSame(self::IMEI_A, $devices[0]->imei);
        $this->assertSame(self::IMEI_B, $devices[1]->imei);
        $this->assertSame('280000.00', (string) $devices[0]->cout_achat);
        $this->assertSame('reconditionne', $devices[0]->etat->value);
        $this->assertSame(2.0, (float) Stock::where('produit_id', $s['phone']->id)->value('quantite'));

        // Vendre un téléphone suivi exige de dire lequel.
        $this->sell($s, ['produit_id' => $s['phone']->id, 'quantite' => 1])->assertStatus(422)->assertJsonPath('code', 'APPAREILS_REQUIS');

        $customer = Customer::factory()->for($s['store'])->create(['nom' => 'Kofi']);
        $saleId = $this->sell($s, ['produit_id' => $s['phone']->id, 'quantite' => 1, 'appareil_ids' => [$devices[1]->id]], ['client_id' => $customer->id])
            ->assertStatus(201)
            ->assertJsonPath('donnees.lignes.0.appareils.0.imei', self::IMEI_B)
            ->json('donnees.id');
        $sold = $devices[1]->refresh();
        $this->assertSame('vendu', $sold->statut->value);
        $this->assertSame($customer->id, $sold->client_id);
        $this->assertSame($saleId, $sold->vente_id);
        $this->assertSame('en_stock', $devices[0]->refresh()->statut->value);

        // Déjà vendu : impossible de le revendre.
        $this->sell($s, ['produit_id' => $s['phone']->id, 'quantite' => 1, 'appareil_ids' => [$devices[1]->id]])->assertStatus(422)->assertJsonPath('code', 'APPAREIL_INDISPONIBLE');

        // Retrouver l'appareil par son IMEI : client, vente, historique.
        $this->getJson("/api/boutiques/{$s['store']->id}/appareils?recherche=643817")->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.client.nom', 'Kofi');
        $this->getJson("/api/boutiques/{$s['store']->id}/appareils/{$sold->id}")
            ->assertJsonPath('donnees.statut', 'vendu')
            ->assertJsonPath('donnees.vente.id', $saleId)
            ->assertJsonPath('donnees.historique.0.evenement', 'vente')
            ->assertJsonPath('donnees.historique.1.evenement', 'entree');

        // Annulation : il redevient disponible.
        $this->postJson("/api/boutiques/{$s['store']->id}/ventes/{$saleId}/annuler", ['motif' => 'Erreur'])->assertStatus(200);
        $this->assertSame('en_stock', $sold->refresh()->statut->value);
        $this->assertNull($sold->client_id);
    }

    public function test_purchase_identifier_errors_write_nothing(): void
    {
        $s = $this->shop();
        $url = "/api/boutiques/{$s['store']->id}/achats";

        $this->postJson($url, ['lignes' => [['produit_id' => $s['phone']->id, 'quantite' => 2, 'cout_unitaire' => 280000, 'appareils' => [self::IMEI_A]]]])
            ->assertStatus(422)->assertJsonPath('code', 'APPAREILS_REQUIS');
        $this->postJson($url, ['lignes' => [['produit_id' => $s['phone']->id, 'quantite' => 2, 'cout_unitaire' => 280000, 'appareils' => [self::IMEI_A, self::IMEI_A]]]])
            ->assertStatus(422)->assertJsonPath('code', 'APPAREIL_EXISTANT');
        $this->assertDatabaseCount('achats', 0);
        $this->assertSame(0, Device::count());

        // Sans identifiants : accepté, ils pourront être saisis plus tard.
        $this->postJson($url, ['lignes' => [['produit_id' => $s['phone']->id, 'quantite' => 3, 'cout_unitaire' => 280000]]])->assertStatus(201);
        $this->receivePhones($s, [self::IMEI_A]);
        $this->postJson($url, ['lignes' => [['produit_id' => $s['phone']->id, 'quantite' => 1, 'cout_unitaire' => 1, 'appareils' => [self::IMEI_A]]]])
            ->assertStatus(422)->assertJsonPath('code', 'APPAREIL_EXISTANT');
    }

    public function test_existing_stock_is_identified_later_and_status_changes_move_the_stock(): void
    {
        $s = $this->shop();
        $url = "/api/boutiques/{$s['store']->id}/appareils";
        $this->postJson("/api/boutiques/{$s['store']->id}/stocks/{$s['phone']->id}/mouvements", ['type' => 'initial', 'quantite' => 2])->assertStatus(201);

        // Les 2 unités déjà en stock reçoivent un identifiant ; une 3e serait de trop.
        $this->postJson($url, ['produit_id' => $s['phone']->id, 'identifiants' => [self::IMEI_A, 'SN-C02XL0GTJGH5']])
            ->assertStatus(201)->assertJsonPath('donnees.1.numero_serie', 'SN-C02XL0GTJGH5');
        $this->postJson($url, ['produit_id' => $s['phone']->id, 'identifiants' => [self::IMEI_B]])->assertStatus(422)->assertJsonPath('code', 'STOCK_INSUFFISANT');
        // Nouvel arrivage : entre en stock en même temps.
        $this->postJson($url, ['produit_id' => $s['phone']->id, 'identifiants' => [self::IMEI_B], 'ajouter_au_stock' => true])->assertStatus(201);
        $this->assertSame(3.0, (float) Stock::where('produit_id', $s['phone']->id)->value('quantite'));

        // Écran cassé : sort du stock (perte valorisée), puis réparé : revient.
        $device = Device::where('imei', self::IMEI_A)->firstOrFail();
        $this->postJson("{$url}/{$device->id}/statut", ['statut' => 'defectueux', 'motif' => 'Écran cassé'])->assertStatus(200)->assertJsonPath('donnees.statut', 'defectueux');
        $this->assertSame(2.0, (float) Stock::where('produit_id', $s['phone']->id)->value('quantite'));
        $this->assertSame('avarie', StockMovement::where('type', 'perte')->firstOrFail()->origine_perte->value);
        $this->postJson("{$url}/{$device->id}/statut", ['statut' => 'en_stock', 'motif' => 'Réparé'])->assertStatus(200);
        $this->assertSame(3.0, (float) Stock::where('produit_id', $s['phone']->id)->value('quantite'));

        // Un appareil vendu ne peut pas être déclaré perdu.
        $this->sell($s, ['produit_id' => $s['phone']->id, 'quantite' => 1, 'appareil_ids' => [$device->id]])->assertStatus(201);
        $this->postJson("{$url}/{$device->id}/statut", ['statut' => 'perdu'])->assertStatus(422)->assertJsonPath('code', 'STATUT_APPAREIL_INVALIDE');

        // Corriger un identifiant ; un IMEI déjà pris est refusé.
        $other = Device::where('imei', self::IMEI_B)->firstOrFail();
        $this->putJson("{$url}/{$other->id}", ['imei_2' => '356938035643825', 'note' => 'Double SIM'])->assertStatus(200)->assertJsonPath('donnees.imei_2', '356938035643825');
        $this->putJson("{$url}/{$other->id}", ['imei' => self::IMEI_A])->assertStatus(422);
    }

    public function test_accessories_stay_in_quantity_and_other_shops_are_untouched(): void
    {
        $s = $this->shop();
        $this->postJson("/api/boutiques/{$s['store']->id}/stocks/{$s['cable']->id}/mouvements", ['type' => 'initial', 'quantite' => 50])->assertStatus(201);
        $this->sell($s, ['produit_id' => $s['cable']->id, 'quantite' => 3])->assertStatus(201);
        $this->assertSame(0, Device::count());

        // Une épicerie sans la feature : le drapeau « suivi à l'unité » n'a aucun effet.
        ['proprietaire' => $grocer, 'store' => $grocery] = $this->createStoreWithFeatures(['produits', 'stock', 'ventes', 'caisse']);
        $register = CashRegister::factory()->for($grocery)->create();
        $this->postJson("/api/boutiques/{$grocery->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);
        $rice = Product::factory()->for($grocery)->create(['suivi_unitaire' => true, 'vente_detail_active' => true, 'prix_detail' => 18000, 'vente_gros_active' => false]);
        $this->postJson("/api/boutiques/{$grocery->id}/stocks/{$rice->id}/mouvements", ['type' => 'initial', 'quantite' => 5])->assertStatus(201);
        $this->postJson("/api/boutiques/{$grocery->id}/ventes/encaisser", ['caisse_id' => $register->id, 'lignes' => [['produit_id' => $rice->id, 'mode_prix' => 'detail', 'quantite' => 2]]])->assertStatus(201);
        $this->getJson("/api/boutiques/{$grocery->id}/appareils")->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');

        // Un appareil d'une autre boutique est introuvable, et invendable ici.
        Sanctum::actingAs($s['owner']);
        $this->receivePhones($s, [self::IMEI_A]);
        $device = Device::firstOrFail();
        Sanctum::actingAs($grocer);
        $this->getJson("/api/boutiques/{$grocery->id}/appareils/{$device->id}")->assertStatus(404);
    }

    public function test_cashiers_look_devices_up_but_only_managers_register_them(): void
    {
        $s = $this->shop();
        $cashier = User::factory()->create();
        $this->postJson("/api/boutiques/{$s['store']->id}/membres", ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);
        $this->receivePhones($s, [self::IMEI_A]);

        Sanctum::actingAs($cashier);
        $this->getJson("/api/boutiques/{$s['store']->id}/appareils?recherche=".self::IMEI_A)->assertStatus(200)->assertJsonCount(1, 'donnees');
        $this->postJson("/api/boutiques/{$s['store']->id}/appareils", ['produit_id' => $s['phone']->id, 'identifiants' => [self::IMEI_B], 'ajouter_au_stock' => true])->assertStatus(403);
        // Mais il vend l'appareil précis.
        $this->sell($s, ['produit_id' => $s['phone']->id, 'quantite' => 1, 'appareil_ids' => [Device::firstOrFail()->id]])->assertStatus(201);
    }
}

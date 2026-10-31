<?php

namespace Tests\Feature\Modules\Repairs;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Employees\Models\Employee;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Tenancy\Models\Store;
use Carbon\CarbonImmutable;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Atelier : dossiers de travaux, devis, matériaux, livraison, rapport — docs/modules.md §Repairs. */
class WorkshopTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private const FEATURES = ['services', 'categories', 'produits', 'stock', 'reparations', 'devis', 'clients', 'employes', 'ventes', 'caisse', 'rapports', 'rapports_gestion'];

    /** @return array{owner: User, store: Store, register: CashRegister, vidange: Service, huile: Product, tech: Employee, client: Customer} */
    private function workshop(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(self::FEATURES);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);
        $vidange = Service::factory()->for($store)->create(['nom' => 'Vidange', 'prix' => 5000]);
        $huile = Product::factory()->for($store)->create(['nom' => 'Huile 5W30', 'unite' => 'litre', 'prix_achat' => 2000, 'vente_detail_active' => true, 'prix_detail' => 3000, 'vente_gros_active' => false]);
        $this->postJson($this->url($store, "stocks/{$huile->id}/mouvements"), ['type' => 'initial', 'quantite' => 20])->assertStatus(201);
        $tech = Employee::factory()->for($store)->create(['nom' => 'Moussa']);
        $client = Customer::factory()->for($store)->create(['nom' => 'Kofi']);

        return ['owner' => $owner, 'store' => $store, 'register' => $register, 'vidange' => $vidange, 'huile' => $huile, 'tech' => $tech, 'client' => $client];
    }

    private function url(Store $store, string $path): string
    {
        return "/api/boutiques/{$store->id}/{$path}";
    }

    public function test_a_workshop_gets_its_features_and_the_fabrication_option(): void
    {
        $this->seed(FeatureSeeder::class);
        $store = Store::factory()->create(['domaine_activite_id' => BusinessDomain::where('slug', 'atelier')->value('id')]);
        $gate = app(FeatureGate::class);
        foreach (['reparations', 'devis', 'stock', 'fournisseurs', 'depenses', 'equipements', 'rapports_gestion'] as $slug) {
            $this->assertTrue($gate->allows($store, $slug), $slug);
        }
        $this->assertFalse($gate->allows($store, 'preparations'));
        $this->assertTrue((bool) DomainFeature::where(['domaine_activite_id' => $store->domaine_activite_id, 'fonctionnalite_id' => Feature::where('slug', 'preparations')->value('id')])->value('optionnelle'));

        $migration = require base_path('app/Modules/Repairs/Database/Migrations/2026_11_16_000001_add_workshop_features.php');
        $migration->up(); // idempotente
        $this->assertSame(1, DomainFeature::where(['domaine_activite_id' => $store->domaine_activite_id, 'fonctionnalite_id' => Feature::where('slug', 'devis')->value('id')])->count());
    }

    public function test_the_specialty_is_saved_in_the_store_settings(): void
    {
        ['store' => $store] = $this->workshop();

        $this->putJson($this->url($store, 'parametres/specialite'), ['specialite' => 'mecanique'])->assertOk()->assertJsonPath('donnees.specialite', 'mecanique');
        $this->assertSame('mecanique', $store->fresh()->parametres['specialite']);
        $this->putJson($this->url($store, 'parametres/specialite'), ['specialite' => 'astronautique'])->assertStatus(422);
    }

    public function test_a_work_order_bills_its_services_tracks_materials_and_technicians(): void
    {
        ['store' => $store, 'register' => $register, 'vidange' => $vidange, 'huile' => $huile, 'tech' => $tech, 'client' => $client] = $this->workshop();
        $helper = Employee::factory()->for($store)->create(['nom' => 'Ali']);

        $repair = $this->postJson($this->url($store, 'reparations'), [
            'client_id' => $client->id, 'appareil' => 'Toyota Corolla', 'panne' => 'Vidange et freins',
            'bien' => ['immatriculation' => 'AB-123-CD', 'kilometrage' => '125000'],
            'techniciens' => [$tech->id, $helper->id],
            'prestations' => [['service_id' => $vidange->id], ['libelle' => 'Main-d’œuvre freins', 'prix_unitaire' => 8000]],
        ])->assertStatus(201)
            ->assertJsonPath('donnees.bien.immatriculation', 'AB-123-CD')
            ->assertJsonPath('donnees.technicien.nom', 'Moussa')
            ->assertJsonCount(2, 'donnees.techniciens')
            ->assertJsonPath('donnees.montant_du', '13000.00')
            ->json('donnees.id');

        // Huile refacturée, chiffon non : seul le premier compte dans le dû.
        $this->postJson($this->url($store, "reparations/{$repair}/pieces"), ['produit_id' => $huile->id, 'quantite' => 4])->assertStatus(201)->assertJsonPath('donnees.montant_du', '25000.00');
        $this->postJson($this->url($store, "reparations/{$repair}/pieces"), ['produit_id' => $huile->id, 'quantite' => 1, 'facturee' => false])
            ->assertStatus(201)->assertJsonPath('donnees.montant_du', '25000.00')->assertJsonPath('donnees.cout_materiaux', '10000.00');
        $this->getJson($this->url($store, "stocks/{$huile->id}"))->assertJsonPath('donnees.quantite', '15.000');

        $this->getJson($this->url($store, "reparations?technicien_id={$helper->id}"))->assertJsonCount(1, 'donnees');
        $this->postJson($this->url($store, "reparations/{$repair}/paiements"), ['montant' => 10000, 'mode' => 'especes', 'caisse_id' => $register->id])->assertStatus(201)->assertJsonPath('donnees.reste_a_payer', '15000.00');
    }

    public function test_delivery_is_distinct_from_payment(): void
    {
        ['store' => $store, 'vidange' => $vidange, 'client' => $client] = $this->workshop();
        $repair = $this->postJson($this->url($store, 'reparations'), ['client_id' => $client->id, 'appareil' => 'Moto', 'panne' => 'Vidange', 'prestations' => [['service_id' => $vidange->id]]])->json('donnees.id');

        $this->postJson($this->url($store, "reparations/{$repair}/statut"), ['statut' => 'livre'])->assertStatus(422)->assertJsonPath('code', 'PAIEMENT_INCOMPLET');
        $this->postJson($this->url($store, "reparations/{$repair}/statut"), ['statut' => 'livre', 'laisser_a_credit' => true, 'remis_a' => 'Son frère', 'reception_confirmee' => true])
            ->assertOk()->assertJsonPath('donnees.statut', 'livre')->assertJsonPath('donnees.reste_a_payer', '0.00')
            ->assertJsonPath('donnees.montant_credit', '5000.00')->assertJsonPath('donnees.remis_a', 'Son frère')->assertJsonPath('donnees.reception_confirmee', true);
        $this->getJson($this->url($store, "clients/{$client->id}"))->assertJsonPath('donnees.solde', '5000.00');
    }

    public function test_a_quote_is_priced_then_turned_into_a_work_order(): void
    {
        ['store' => $store, 'vidange' => $vidange, 'huile' => $huile, 'tech' => $tech] = $this->workshop();

        $quote = $this->postJson($this->url($store, 'devis'), [
            'nouveau_client' => ['nom' => 'Awa', 'telephone' => '0700000001'],
            'objet' => 'Table en bois massif', 'description' => 'Table 6 places', 'valide_jusqu_au' => now()->addDays(15)->toDateString(), 'montant_remise' => 1000,
            'lignes' => [
                ['service_id' => $vidange->id],
                ['produit_id' => $huile->id, 'quantite' => 2],
                ['type' => 'main_oeuvre', 'libelle' => 'Assemblage', 'quantite' => 3, 'prix_unitaire' => 2000],
                ['type' => 'frais', 'libelle' => 'Transport', 'prix_unitaire' => 1500],
            ],
        ])->assertStatus(201)
            ->assertJsonPath('donnees.statut', 'brouillon')
            ->assertJsonPath('donnees.sous_total', '18500.00')
            ->assertJsonPath('donnees.montant_total', '17500.00')
            ->assertJsonPath('donnees.lignes.1.type', 'materiau')
            ->json('donnees.id');

        $this->postJson($this->url($store, "devis/{$quote}/statut"), ['statut' => 'accepte'])->assertOk()->assertJsonPath('donnees.statut', 'accepte');
        $repair = $this->postJson($this->url($store, "devis/{$quote}/transformer"), ['technicien_id' => $tech->id, 'prevu_le' => now()->addDays(5)->toDateString()])
            ->assertStatus(201)->assertJsonPath('donnees.appareil', 'Table en bois massif')->assertJsonPath('donnees.montant_du', '17500.00')
            ->assertJsonCount(5, 'donnees.prestations')->assertJsonPath('donnees.devis.id', $quote)->json('donnees.id');

        $this->getJson($this->url($store, "devis/{$quote}"))->assertJsonPath('donnees.statut', 'transforme')->assertJsonPath('donnees.reparation.id', $repair);
        $this->postJson($this->url($store, "devis/{$quote}/transformer"))->assertStatus(422)->assertJsonPath('code', 'DEVIS_FIGE');
        $this->putJson($this->url($store, "devis/{$quote}"), ['objet' => 'Autre'])->assertStatus(422)->assertJsonPath('code', 'DEVIS_FIGE');
    }

    public function test_an_expired_quote_cannot_be_accepted(): void
    {
        ['store' => $store, 'vidange' => $vidange] = $this->workshop();
        $quote = $this->postJson($this->url($store, 'devis'), ['nom_client' => 'Ibrahim', 'objet' => 'Portail', 'valide_jusqu_au' => now()->subDay()->toDateString(), 'lignes' => [['service_id' => $vidange->id]]])
            ->assertStatus(201)->assertJsonPath('donnees.statut', 'expire')->json('donnees.id');

        $this->getJson($this->url($store, 'devis?statut=expire'))->assertJsonCount(1, 'donnees');
        $this->postJson($this->url($store, "devis/{$quote}/statut"), ['statut' => 'accepte'])->assertStatus(422)->assertJsonPath('code', 'DEVIS_EXPIRE');
    }

    public function test_late_work_orders_are_counted_and_photos_attached(): void
    {
        Storage::fake('public');
        ['store' => $store, 'client' => $client, 'tech' => $tech] = $this->workshop();
        $late = $this->postJson($this->url($store, 'reparations'), ['client_id' => $client->id, 'appareil' => 'Ordinateur', 'panne' => 'Écran', 'technicien_id' => $tech->id, 'prevu_le' => now()->subDays(2)->toDateString()])->json('donnees.id');
        $this->postJson($this->url($store, 'reparations'), ['client_id' => $client->id, 'appareil' => 'Imprimante', 'panne' => 'Bourrage', 'prevu_le' => now()->addDays(2)->toDateString()]);

        $this->getJson($this->url($store, 'reparations?en_retard=1'))->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.en_retard', true);
        $this->getJson($this->url($store, 'reparations/resume'))->assertJsonPath('donnees.en_cours', 2)->assertJsonPath('donnees.en_retard', 1);
        $this->postJson($this->url($store, "reparations/{$late}/statut"), ['statut' => 'attente_validation'])->assertOk();

        $this->post($this->url($store, "reparations/{$late}/photos"), ['image' => UploadedFile::fake()->image('ecran.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(201)->assertJsonCount(1, 'donnees.photos');
        $this->deleteJson($this->url($store, "reparations/{$late}/photos/0"))->assertOk()->assertJsonCount(0, 'donnees.photos');

        $report = $this->getJson($this->url($store, 'rapports/gestion?periode=aujourdhui'))->assertOk()->json('donnees.reparations');
        $this->assertSame(2, $report['crees']);
        $this->assertSame(1, $report['en_retard']);
        $this->assertSame('Moussa', $report['par_technicien'][0]['nom']);
    }

    public function test_a_service_can_have_a_variable_price(): void
    {
        ['store' => $store] = $this->workshop();

        $this->postJson($this->url($store, 'services'), ['nom' => 'Confection sur mesure', 'prix' => 15000, 'prix_variable' => true])
            ->assertStatus(201)->assertJsonPath('donnees.prix_variable', true);
        $this->assertNotNull(CarbonImmutable::now());
    }
}

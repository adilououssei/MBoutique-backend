<?php

namespace Tests\Feature\Modules\Appointments;

use App\Models\User;
use App\Modules\Appointments\Models\ServiceConsumption;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Tenancy\Models\Store;
use Carbon\CarbonImmutable;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Coiffure : formules, client sans rendez-vous, consommation réelle, paiement partiel, fiches, tableau de bord, rapports. */
class HairSalonTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private const FEATURES = ['clients', 'services', 'rendez_vous', 'employes', 'ventes', 'caisse', 'rapports', 'rapports_gestion', 'produits', 'stock', 'consommables', 'forfaits', 'fidelite', 'commissions', 'depenses', 'equipements'];

    /** @return array{owner: User, store: Store, register: CashRegister, moussa: int, coupe: int, barbe: int, formule: int, client: int, gel: int} */
    private function salon(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(self::FEATURES);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson($this->url($store, "caisses/{$register->id}/sessions"), ['montant_ouverture' => 10000])->assertStatus(201);

        $gel = $this->postJson($this->url($store, 'produits'), ['nom' => 'Gel coiffant', 'prix_achat' => 1000, 'vente_detail_active' => true, 'prix_detail' => 2500])->assertStatus(201)->json('donnees.id');
        $this->postJson($this->url($store, "stocks/{$gel}/mouvements"), ['type' => 'initial', 'quantite' => 10])->assertStatus(201);

        $coupe = $this->postJson($this->url($store, 'services'), ['nom' => 'Coupe homme', 'prix' => 3000, 'duree_minutes' => 20, 'consommables' => [['produit_id' => $gel, 'quantite' => 0.1]]])->assertStatus(201)->json('donnees.id');
        $barbe = $this->postJson($this->url($store, 'services'), ['nom' => 'Taille de barbe', 'prix' => 2000, 'duree_minutes' => 15])->assertStatus(201)->json('donnees.id');
        $formule = $this->postJson($this->url($store, 'services'), ['nom' => 'Coupe + barbe', 'prix' => 4500, 'est_formule' => true, 'composants' => [$coupe, $barbe]])
            ->assertStatus(201)->assertJsonPath('donnees.est_formule', true)->assertJsonPath('donnees.duree_effective', 35)
            ->assertJsonPath('donnees.cout_consommables', '100.00')->assertJsonCount(2, 'donnees.composants')->json('donnees.id');

        $moussa = $this->postJson($this->url($store, 'employes'), ['nom' => 'Moussa', 'specialites' => 'Dégradés, barbe', 'taux_commission' => 10])->assertStatus(201)
            ->assertJsonPath('donnees.specialites', 'Dégradés, barbe')->json('donnees.id');
        $client = $this->postJson($this->url($store, 'clients'), ['nom' => 'Ibrahim', 'telephone' => '0700000001', 'sexe' => 'homme', 'date_naissance' => '1990-05-01', 'preferences' => ['coupe_habituelle' => 'dégradé bas']])
            ->assertStatus(201)->assertJsonPath('donnees.sexe', 'homme')->assertJsonPath('donnees.date_naissance', '1990-05-01')->json('donnees.id');

        return compact('owner', 'store', 'register', 'moussa', 'coupe', 'barbe', 'formule', 'client', 'gel');
    }

    private function url(Store $store, string $path): string
    {
        return "/api/boutiques/{$store->id}/{$path}";
    }

    private function stock(Store $store, int $product): string
    {
        return $this->getJson($this->url($store, "stocks/{$product}"))->json('donnees.quantite');
    }

    public function test_the_hair_salon_domain_gets_its_tools_and_commissions_stay_optional(): void
    {
        $this->seed(FeatureSeeder::class);
        $store = Store::factory()->create(['domaine_activite_id' => BusinessDomain::where('slug', 'coiffure')->value('id')]);
        foreach (['produits', 'stock', 'fournisseurs', 'depenses', 'equipements', 'rapports_gestion', 'consommables', 'forfaits', 'fidelite'] as $slug) {
            $this->assertTrue(app(FeatureGate::class)->allows($store, $slug), $slug);
        }
        $this->assertFalse(app(FeatureGate::class)->allows($store, 'commissions'));
    }

    public function test_a_formula_books_for_its_total_duration_and_consumes_its_components_products(): void
    {
        ['store' => $store, 'register' => $register, 'moussa' => $moussa, 'coupe' => $coupe, 'barbe' => $barbe, 'formule' => $formule, 'client' => $client, 'gel' => $gel] = $this->salon();
        // Habilité aux composants, pas à la formule elle-même : accepté.
        $this->putJson($this->url($store, "employes/{$moussa}/planning"), ['services' => [$coupe, $barbe]])->assertOk();

        $rdv = $this->postJson($this->url($store, 'rendez-vous'), ['client_id' => $client, 'employe_id' => $moussa, 'service_id' => $formule, 'debut_le' => CarbonImmutable::now()->addDay()->setTime(10, 0)->toIso8601String()])
            ->assertStatus(201)->json('donnees');
        $this->assertSame(35, (int) CarbonImmutable::parse($rdv['debut_le'])->diffInMinutes(CarbonImmutable::parse($rdv['fin_le'])));

        // Annulé : ni vente, ni consommation.
        $cancelled = $this->postJson($this->url($store, 'rendez-vous'), ['client_id' => $client, 'employe_id' => $moussa, 'service_id' => $coupe, 'debut_le' => CarbonImmutable::now()->addDays(2)->setTime(10, 0)->toIso8601String()])->json('donnees.id');
        $this->postJson($this->url($store, "rendez-vous/{$cancelled}/annuler"))->assertOk();
        $this->assertSame('10.000', $this->stock($store, $gel));

        $this->getJson($this->url($store, "rendez-vous/{$rdv['id']}/consommations"))->assertOk()->assertJsonPath('donnees.0.quantite', '0.100');
        $done = $this->postJson($this->url($store, "rendez-vous/{$rdv['id']}/encaisser"), ['caisse_id' => $register->id])->assertOk()->json('donnees');
        $this->getJson($this->url($store, "ventes/{$done['vente_id']}"))->assertJsonPath('donnees.total', '4500.00');
        $this->assertSame('9.900', $this->stock($store, $gel));
        // Double encaissement : aucune seconde sortie.
        $this->postJson($this->url($store, "rendez-vous/{$rdv['id']}/encaisser"), ['caisse_id' => $register->id])->assertOk();
        $this->assertSame('9.900', $this->stock($store, $gel));
    }

    public function test_a_walk_in_client_is_served_with_the_actual_consumption_and_a_partial_payment(): void
    {
        ['owner' => $owner, 'store' => $store, 'register' => $register, 'moussa' => $moussa, 'coupe' => $coupe, 'client' => $client, 'gel' => $gel] = $this->salon();

        $walkIn = $this->postJson($this->url($store, 'rendez-vous'), ['client_id' => $client, 'employe_id' => $moussa, 'service_id' => $coupe, 'statut' => 'en_cours', 'debut_le' => now()->toIso8601String()])
            ->assertStatus(201)->assertJsonPath('donnees.statut', 'en_cours')->json('donnees.id');
        $this->assertNotNull($this->getJson($this->url($store, "rendez-vous/{$walkIn}"))->json('donnees.commence_le'));

        // Un caissier ne corrige pas la consommation.
        $cashier = User::factory()->create();
        $this->postJson($this->url($store, 'membres'), ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);
        Sanctum::actingAs($cashier);
        $this->postJson($this->url($store, "rendez-vous/{$walkIn}/encaisser"), ['caisse_id' => $register->id, 'consommations' => [['produit_id' => $gel, 'quantite' => 0.3]]])->assertStatus(403);
        Sanctum::actingAs($owner);

        // Le gérant : 0,3 réellement consommé ; paiement partiel 1 000 sur 3 000 + produit vendu.
        $done = $this->postJson($this->url($store, "rendez-vous/{$walkIn}/encaisser"), [
            'caisse_id' => $register->id, 'mode_paiement' => 'credit', 'acompte' => 1000,
            'produits' => [['produit_id' => $gel, 'mode_prix' => 'detail', 'quantite' => 1]],
            'consommations' => [['produit_id' => $gel, 'quantite' => 0.3]],
        ])->assertOk()->json('donnees');
        // 10 − 1 vendu − 0,3 consommé.
        $this->assertSame('8.700', $this->stock($store, $gel));
        $trace = ServiceConsumption::query()->where('vente_id', $done['vente_id'])->firstOrFail();
        $this->assertSame('0.100', $trace->quantite_prevue);
        $this->assertSame('0.300', $trace->quantite);
        $this->assertTrue($trace->ajustee);
        $this->getJson($this->url($store, "clients/{$client}/compte"))->assertOk()->assertJsonPath('meta.solde', '4500.00');

        // Fiche client et activité du coiffeur.
        $this->getJson($this->url($store, "clients/{$client}/statistiques"))->assertOk()
            ->assertJsonPath('donnees.visites', 1)->assertJsonPath('donnees.total_depense', '5500.00')
            ->assertJsonPath('donnees.prestations.0.nom', 'Coupe homme')->assertJsonPath('donnees.produits.0.nom', 'Gel coiffant');
        $this->getJson($this->url($store, "employes/{$moussa}/activite"))->assertOk()
            ->assertJsonPath('donnees.prestations', 1)->assertJsonPath('donnees.chiffre_affaires', '3000.00')->assertJsonPath('donnees.commissions.dues', '300.00');

        // Annulation de la vente : la consommation revient au stock (une seule fois).
        $this->postJson($this->url($store, "ventes/{$done['vente_id']}/annuler"), ['motif' => 'Erreur', 'caisse_id' => $register->id])->assertOk();
        $this->assertSame('10.000', $this->stock($store, $gel));
        $this->assertSame(2, StockMovement::query()->where('produit_id', $gel)->where('quantite', '<', 0)->count());
    }

    public function test_a_package_session_still_consumes_its_products(): void
    {
        ['store' => $store, 'register' => $register, 'moussa' => $moussa, 'coupe' => $coupe, 'client' => $client, 'gel' => $gel] = $this->salon();
        $package = $this->postJson($this->url($store, 'forfaits'), ['nom' => '4 coupes', 'prix' => 10000, 'nombre_seances' => 4, 'services' => [$coupe]])->json('donnees.id');
        $sold = $this->postJson($this->url($store, "forfaits/{$package}/vendre"), ['client_id' => $client, 'caisse_id' => $register->id])->assertStatus(201)->json('donnees.id');
        $rdv = $this->postJson($this->url($store, 'rendez-vous'), ['client_id' => $client, 'employe_id' => $moussa, 'service_id' => $coupe, 'forfait_client_id' => $sold, 'debut_le' => now()->addHour()->toIso8601String()])->json('donnees.id');

        $done = $this->postJson($this->url($store, "rendez-vous/{$rdv}/encaisser"), ['caisse_id' => $register->id])->assertOk()->assertJsonPath('donnees.forfait.seances_restantes', 3)->json('donnees');
        $this->getJson($this->url($store, "ventes/{$done['vente_id']}"))->assertJsonPath('donnees.total', '0.00');
        $this->assertSame('9.900', $this->stock($store, $gel));
    }

    public function test_dashboard_and_reports_separate_services_from_products_and_accept_custom_periods(): void
    {
        ['store' => $store, 'register' => $register, 'moussa' => $moussa, 'coupe' => $coupe, 'gel' => $gel] = $this->salon();
        $this->postJson($this->url($store, 'ventes/encaisser'), ['caisse_id' => $register->id, 'lignes' => [
            ['service_id' => $coupe, 'employe_id' => $moussa, 'quantite' => 2],
            ['produit_id' => $gel, 'mode_prix' => 'detail', 'quantite' => 1],
        ]])->assertStatus(201);

        $dashboard = $this->getJson($this->url($store, 'rapports/tableau-de-bord?periode=aujourdhui'))->assertOk()->json('donnees');
        $this->assertSame('1.000', $dashboard['resume']['articles_vendus']);
        $this->assertSame(2, $dashboard['resume']['prestations']);
        $this->assertSame('6000.00', $dashboard['resume']['chiffre_affaires_prestations']);
        $this->assertSame('Coupe homme', $dashboard['salon']['meilleures_prestations'][0]['nom']);
        $this->assertSame('Moussa', $dashboard['salon']['employes_actifs'][0]['nom']);

        $today = now()->toDateString();
        $report = $this->getJson($this->url($store, "rapports/gestion?periode=personnalisee&du={$today}&au={$today}"))->assertOk()->json('donnees');
        $this->assertSame('personnalisee', $report['periode']['code']);
        $this->assertSame('6000.00', $report['salon']['chiffre_affaires_prestations']);
        $this->assertSame('2500.00', $report['salon']['chiffre_affaires_produits']);
        $this->assertSame('Gel coiffant', $report['salon']['produits_plus_consommes'][0]['nom']);
        $this->getJson($this->url($store, 'rapports/gestion?periode=cette_annee'))->assertOk()->assertJsonPath('donnees.periode.code', 'cette_annee');
        $this->getJson($this->url($store, 'rapports/gestion?periode=personnalisee'))->assertStatus(422);
    }

    public function test_expenses_keep_their_payment_mean_and_receipt_and_equipment_its_cost(): void
    {
        Storage::fake('local');
        ['store' => $store] = $this->salon();
        $expense = $this->postJson($this->url($store, 'depenses'), ['categorie' => 'loyer', 'libelle' => 'Loyer novembre', 'montant' => 50000, 'mode' => 'externe', 'moyen_paiement' => 'mobile_money'])
            ->assertStatus(201)->assertJsonPath('donnees.moyen_paiement', 'mobile_money')->json('donnees.id');
        $this->post($this->url($store, "depenses/{$expense}/justificatif"), ['image' => UploadedFile::fake()->image('recu.jpg')])->assertOk()->assertJsonPath('donnees.a_justificatif', true);
        $this->get($this->url($store, "depenses/{$expense}/justificatif"))->assertOk();

        $this->postJson($this->url($store, 'equipements'), ['nom' => 'Tondeuse Wahl', 'type' => 'tondeuse', 'cout_acquisition' => 35000])
            ->assertStatus(201)->assertJsonPath('donnees.cout_acquisition', '35000.00');
    }

    public function test_another_store_cannot_read_a_customer_or_employee_activity(): void
    {
        ['store' => $store, 'client' => $client, 'moussa' => $moussa] = $this->salon();
        ['store' => $other] = $this->createStoreWithFeatures(self::FEATURES);
        $this->getJson($this->url($other, "clients/{$client}/statistiques"))->assertNotFound();
        $this->getJson($this->url($other, "employes/{$moussa}/activite"))->assertNotFound();
        $this->assertNotNull($store);
    }
}

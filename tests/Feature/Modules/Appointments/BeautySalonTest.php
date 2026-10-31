<?php

namespace Tests\Feature\Modules\Appointments;

use App\Modules\Appointments\Models\Commission;
use App\Modules\Appointments\Models\CustomerPackage;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Employees\Models\EmployeePayment;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Tenancy\Models\Store;
use Carbon\CarbonImmutable;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Salon de beauté : planning, prestations multiples, encaissement, consommables, forfaits, commissions, fidélité, rapport. */
class BeautySalonTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private const FEATURES = ['clients', 'services', 'rendez_vous', 'employes', 'ventes', 'caisse', 'rapports', 'rapports_gestion', 'produits', 'stock', 'consommables', 'forfaits', 'fidelite', 'commissions'];

    /** @return array{store: Store, register: CashRegister, aicha: int, fatou: int, manucure: int, soin: int, client: int, creme: int} */
    private function salon(array $features = self::FEATURES): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures($features);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson($this->url($store, "caisses/{$register->id}/sessions"), ['montant_ouverture' => 10000])->assertStatus(201);

        $creme = $this->postJson($this->url($store, 'produits'), ['nom' => 'Crème soin', 'prix_achat' => 2000, 'vente_detail_active' => true, 'prix_detail' => 5000])->assertStatus(201)->json('donnees.id');
        $this->postJson($this->url($store, "stocks/{$creme}/mouvements"), ['type' => 'initial', 'quantite' => 10])->assertStatus(201);

        $manucure = $this->postJson($this->url($store, 'services'), ['nom' => 'Manucure', 'prix' => 5000, 'duree_minutes' => 45])->assertStatus(201)->json('donnees.id');
        $soin = $this->postJson($this->url($store, 'services'), [
            'nom' => 'Soin visage', 'prix' => 10000, 'duree_minutes' => 60,
            'commission_type' => 'montant', 'commission_valeur' => 1500,
            'consommables' => [['produit_id' => $creme, 'quantite' => 0.5]],
        ])->assertStatus(201)->assertJsonPath('donnees.cout_consommables', '1000.00')->json('donnees.id');

        $aicha = $this->postJson($this->url($store, 'employes'), ['nom' => 'Aïcha', 'taux_commission' => 10])->assertStatus(201)->json('donnees.id');
        $fatou = $this->postJson($this->url($store, 'employes'), ['nom' => 'Fatou'])->assertStatus(201)->json('donnees.id');
        $client = $this->postJson($this->url($store, 'clients'), ['nom' => 'Mariam', 'preferences' => ['type_peau' => 'sèche', 'allergies' => 'parfum']])->assertStatus(201)->json('donnees.id');

        return compact('store', 'register', 'aicha', 'fatou', 'manucure', 'soin', 'client', 'creme');
    }

    private function url(Store $store, string $path): string
    {
        return "/api/boutiques/{$store->id}/{$path}";
    }

    /** Un lundi à venir, à l'heure UTC donnée (les boutiques de test sont en UTC). */
    private function monday(string $time): CarbonImmutable
    {
        [$h, $m] = explode(':', $time);

        return CarbonImmutable::now('UTC')->next(CarbonImmutable::MONDAY)->addWeek()->setTime((int) $h, (int) $m);
    }

    public function test_the_beauty_salon_domain_gets_its_features_and_commissions_stay_optional(): void
    {
        $this->seed(FeatureSeeder::class);
        $salon = Store::factory()->create(['domaine_activite_id' => BusinessDomain::where('slug', 'salon_beaute')->value('id')]);
        foreach (['produits', 'stock', 'fournisseurs', 'conservation', 'depenses', 'equipements', 'rapports_gestion', 'promotions', 'consommables', 'forfaits', 'fidelite'] as $slug) {
            $this->assertTrue(app(FeatureGate::class)->allows($salon, $slug), $slug);
        }
        $this->assertFalse(app(FeatureGate::class)->allows($salon, 'commissions'));
        $butcher = Store::factory()->create(['domaine_activite_id' => BusinessDomain::where('slug', 'boucherie')->value('id')]);
        $this->assertFalse(app(FeatureGate::class)->allows($butcher, 'forfaits'));
    }

    public function test_planning_rules_apply_only_once_configured(): void
    {
        ['store' => $store, 'aicha' => $aicha, 'fatou' => $fatou, 'manucure' => $manucure, 'soin' => $soin, 'client' => $client] = $this->salon();
        $book = fn (array $data) => $this->postJson($this->url($store, 'rendez-vous'), ['client_id' => $client, ...$data]);

        // Rien de configuré : un rendez-vous à 22 h passe (comportement d'avant).
        $book(['service_id' => $manucure, 'employe_id' => $aicha, 'debut_le' => $this->monday('22:00')->toIso8601String()])->assertStatus(201);

        $this->putJson($this->url($store, 'parametres/salon'), ['horaires_ouverture' => [1 => ['ouvre' => '09:00', 'ferme' => '19:00'], 2 => ['ouvre' => '09:00', 'ferme' => '19:00']]])->assertOk();
        $this->putJson($this->url($store, "employes/{$aicha}/planning"), [
            'services' => [$manucure],
            'horaires' => [['jour' => 1, 'debut' => '09:00', 'fin' => '17:00', 'pause_debut' => '12:00', 'pause_fin' => '13:00']],
        ])->assertOk()->assertJsonPath('donnees.services.0.id', $manucure);

        $book(['service_id' => $manucure, 'employe_id' => $aicha, 'debut_le' => $this->monday('20:00')->toIso8601String()])->assertStatus(422)->assertJsonPath('code', 'SALON_FERME');
        $book(['service_id' => $manucure, 'employe_id' => $aicha, 'debut_le' => $this->monday('17:30')->toIso8601String()])->assertStatus(422)->assertJsonPath('code', 'EMPLOYE_INDISPONIBLE');
        $book(['service_id' => $manucure, 'employe_id' => $aicha, 'debut_le' => $this->monday('11:30')->toIso8601String()])->assertStatus(422)->assertJsonPath('code', 'EMPLOYE_INDISPONIBLE');
        $book(['service_id' => $soin, 'employe_id' => $aicha, 'debut_le' => $this->monday('09:00')->toIso8601String()])->assertStatus(422)->assertJsonPath('code', 'EMPLOYE_NON_HABILITE');
        $book(['service_id' => $manucure, 'employe_id' => $aicha, 'debut_le' => $this->monday('09:00')->addDay()->toIso8601String()])->assertStatus(422)->assertJsonPath('code', 'EMPLOYE_INDISPONIBLE');

        // Plusieurs prestations : durée = somme, chaque employé vérifié ; statut « en attente ».
        $rdv = $book([
            'services' => [['service_id' => $manucure], ['service_id' => $soin, 'employe_id' => $fatou]],
            'employe_id' => $aicha,
            'statut' => 'en_attente',
            'debut_le' => $this->monday('09:00')->toIso8601String(),
        ])->assertStatus(201)->assertJsonPath('donnees.statut', 'en_attente')->assertJsonCount(2, 'donnees.prestations')
            ->assertJsonPath('donnees.montant_prestations', '15000.00')->json('donnees');
        $this->assertSame(105, (int) CarbonImmutable::parse($rdv['debut_le'])->diffInMinutes(CarbonImmutable::parse($rdv['fin_le'])));
        // Fatou est occupée sur tout le créneau du rendez-vous partagé.
        $book(['service_id' => $soin, 'employe_id' => $fatou, 'debut_le' => $this->monday('10:00')->toIso8601String()])->assertStatus(422)->assertJsonPath('code', 'CRENEAU_INDISPONIBLE');

        // Congé : refusé ce jour-là.
        $this->postJson($this->url($store, "employes/{$fatou}/absences"), ['type' => 'conge', 'du' => $this->monday('00:00')->addDays(7)->toDateString(), 'au' => $this->monday('00:00')->addDays(8)->toDateString()])->assertStatus(201);
        $book(['service_id' => $soin, 'employe_id' => $fatou, 'debut_le' => $this->monday('14:00')->addDays(7)->toIso8601String()])->assertStatus(422)->assertJsonPath('code', 'EMPLOYE_INDISPONIBLE');

        // Créneaux libres : Aïcha seule habilitée à la manucure ; ni 9 h–10 h 45 (pris) ni la pause.
        $slots = $this->getJson($this->url($store, "rendez-vous/creneaux?date={$this->monday('00:00')->toDateString()}&services={$manucure}"))->assertOk()->json('donnees.employes');
        $aichaSlots = collect($slots)->firstWhere('employe_id', $aicha)['creneaux'];
        $this->assertNotContains('09:00', $aichaSlots);
        $this->assertNotContains('11:30', $aichaSlots);
        $this->assertContains('10:45', $aichaSlots);
        $this->assertContains('13:00', $aichaSlots);
        $this->assertNotContains('16:30', $aichaSlots);
        $this->assertContains('16:15', $aichaSlots);
    }

    public function test_checkout_bills_every_service_deducts_consumables_once_and_records_commissions_and_points(): void
    {
        ['store' => $store, 'register' => $register, 'aicha' => $aicha, 'fatou' => $fatou, 'manucure' => $manucure, 'soin' => $soin, 'client' => $client, 'creme' => $creme] = $this->salon();
        $this->putJson($this->url($store, 'parametres/salon'), ['fidelite' => ['montant_par_point' => 1000, 'valeur_point' => 10]])->assertOk();

        $rdv = $this->postJson($this->url($store, 'rendez-vous'), [
            'client_id' => $client, 'employe_id' => $aicha,
            'services' => [['service_id' => $manucure], ['service_id' => $soin, 'employe_id' => $fatou]],
            'debut_le' => $this->monday('10:00')->toIso8601String(),
            'acompte' => 3000, 'caisse_id' => $register->id,
        ])->assertStatus(201)->assertJsonPath('donnees.acompte', '3000.00')->json('donnees.id');

        $this->postJson($this->url($store, "rendez-vous/{$rdv}/statut"), ['statut' => 'en_cours'])->assertOk()->assertJsonPath('donnees.statut', 'en_cours');
        // Les consommables ne sortent pas avant l'encaissement.
        $this->getJson($this->url($store, "stocks/{$creme}"))->assertJsonPath('donnees.quantite', '10.000');

        $done = $this->postJson($this->url($store, "rendez-vous/{$rdv}/encaisser"), [
            'caisse_id' => $register->id,
            'produits' => [['produit_id' => $creme, 'mode_prix' => 'detail', 'quantite' => 1]],
        ])->assertOk()->assertJsonPath('donnees.statut', 'termine')->json('donnees');
        $sale = $this->getJson($this->url($store, "ventes/{$done['vente_id']}"))->assertOk()->assertJsonPath('donnees.total', '20000.00')->json('donnees');
        $this->assertSame([$aicha, $fatou], collect($sale['lignes'])->where('type', 'service')->sortBy('service_id')->pluck('employe_id')->values()->all());
        // Crème : 1 vendue + 0,5 consommée par le soin.
        $this->getJson($this->url($store, "stocks/{$creme}"))->assertJsonPath('donnees.quantite', '8.500');
        // Double appui : aucune seconde vente.
        $this->postJson($this->url($store, "rendez-vous/{$rdv}/encaisser"), ['caisse_id' => $register->id])->assertOk()->assertJsonPath('donnees.vente_id', $done['vente_id']);

        // Commissions : 10 % de la manucure pour Aïcha, 1 500 fixe pour le soin de Fatou.
        $this->assertSame('500.00', Commission::query()->where('employe_id', $aicha)->value('montant'));
        $this->assertSame('1500.00', Commission::query()->where('employe_id', $fatou)->value('montant'));
        // Fidélité : 20 points pour 20 000.
        $this->getJson($this->url($store, "clients/{$client}/fidelite"))->assertJsonPath('donnees.points', 20)->assertJsonPath('donnees.valeur', '200.00');

        // Annulation : stock remis, commissions annulées, points repris.
        $this->postJson($this->url($store, "ventes/{$done['vente_id']}/annuler"), ['motif' => 'Erreur', 'caisse_id' => $register->id])->assertOk();
        $this->getJson($this->url($store, "stocks/{$creme}"))->assertJsonPath('donnees.quantite', '10.000');
        $this->assertSame(0, Commission::query()->where('statut', 'due')->count());
        $this->getJson($this->url($store, "clients/{$client}/fidelite"))->assertJsonPath('donnees.points', 0);
    }

    public function test_loyalty_points_become_a_discount(): void
    {
        ['store' => $store, 'register' => $register, 'manucure' => $manucure, 'client' => $client] = $this->salon();
        $this->putJson($this->url($store, 'parametres/salon'), ['fidelite' => ['montant_par_point' => 1000, 'valeur_point' => 10]])->assertOk();
        $this->postJson($this->url($store, "clients/{$client}/fidelite/ajustement"), ['points' => 100, 'note' => 'Bienvenue'])->assertOk()->assertJsonPath('donnees.points', 100);

        $checkout = fn (int $points) => $this->postJson($this->url($store, 'ventes/encaisser'), ['caisse_id' => $register->id, 'client_id' => $client, 'points_utilises' => $points, 'lignes' => [['service_id' => $manucure, 'quantite' => 1]]]);
        $checkout(500)->assertStatus(422)->assertJsonPath('code', 'POINTS_INSUFFISANTS');
        $checkout(50)->assertStatus(201)->assertJsonPath('donnees.total', '4500.00')->assertJsonPath('donnees.remise_fidelite', '500.00');
        // 100 − 50 utilisés + 4 gagnés (4 500).
        $this->getJson($this->url($store, "clients/{$client}/fidelite"))->assertJsonPath('donnees.points', 54)->assertJsonCount(3, 'donnees.mouvements');
    }

    public function test_packages_are_sold_and_sessions_count_down_only_when_the_appointment_is_done(): void
    {
        ['store' => $store, 'register' => $register, 'aicha' => $aicha, 'manucure' => $manucure, 'soin' => $soin, 'client' => $client] = $this->salon();
        $package = $this->postJson($this->url($store, 'forfaits'), ['nom' => '5 manucures', 'prix' => 20000, 'nombre_seances' => 5, 'validite_jours' => 90, 'services' => [$manucure]])
            ->assertStatus(201)->json('donnees.id');
        $sold = $this->postJson($this->url($store, "forfaits/{$package}/vendre"), ['client_id' => $client, 'caisse_id' => $register->id])
            ->assertStatus(201)->assertJsonPath('donnees.seances_restantes', 5)->json('donnees');
        $this->getJson($this->url($store, "ventes/{$sold['vente_id']}"))->assertJsonPath('donnees.total', '20000.00')->assertJsonPath('donnees.lignes.0.type', 'forfait');

        // Forfait sans la prestation demandée : refusé.
        $this->postJson($this->url($store, 'rendez-vous'), ['client_id' => $client, 'service_id' => $soin, 'forfait_client_id' => $sold['id'], 'debut_le' => $this->monday('10:00')->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('code', 'FORFAIT_INVALIDE');

        $rdv = $this->postJson($this->url($store, 'rendez-vous'), [
            'client_id' => $client, 'employe_id' => $aicha, 'forfait_client_id' => $sold['id'],
            'services' => [['service_id' => $manucure], ['service_id' => $soin]],
            'debut_le' => $this->monday('10:00')->toIso8601String(),
        ])->assertStatus(201)->json('donnees.id');
        $this->getJson($this->url($store, 'forfaits-clients?client_id='.$client))->assertJsonPath('donnees.0.seances_restantes', 5);

        // La manucure est couverte : seul le soin est facturé ; une séance décomptée.
        $done = $this->postJson($this->url($store, "rendez-vous/{$rdv}/encaisser"), ['caisse_id' => $register->id])->assertOk()
            ->assertJsonPath('donnees.forfait.seances_restantes', 4)->json('donnees');
        $this->getJson($this->url($store, "ventes/{$done['vente_id']}"))->assertJsonPath('donnees.total', '10000.00');

        // Forfait expiré : inutilisable.
        CustomerPackage::query()->whereKey($sold['id'])->update(['expire_le' => now()->subDay()->toDateString()]);
        $this->getJson($this->url($store, 'forfaits-clients?statut=expire'))->assertJsonCount(1, 'donnees');
        $this->postJson($this->url($store, 'rendez-vous'), ['client_id' => $client, 'service_id' => $manucure, 'forfait_client_id' => $sold['id'], 'debut_le' => $this->monday('15:00')->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('code', 'FORFAIT_INVALIDE');
    }

    public function test_commissions_are_paid_through_payroll_and_a_later_cancellation_is_deducted(): void
    {
        ['store' => $store, 'register' => $register, 'aicha' => $aicha, 'manucure' => $manucure] = $this->salon();
        $sale = $this->postJson($this->url($store, 'ventes/encaisser'), ['caisse_id' => $register->id, 'lignes' => [['service_id' => $manucure, 'employe_id' => $aicha, 'quantite' => 2]]])
            ->assertStatus(201)->json('donnees.id');
        $this->getJson($this->url($store, 'commissions'))->assertOk()->assertJsonPath('donnees.0.du', '1000.00');

        $this->postJson($this->url($store, 'commissions/payer'), ['employe_id' => $aicha, 'mode' => 'externe'])->assertOk()->assertJsonPath('donnees.montant', '1000.00');
        $this->assertSame('commission', EmployeePayment::query()->where('employe_id', $aicha)->value('type')->value);
        $this->postJson($this->url($store, 'commissions/payer'), ['employe_id' => $aicha, 'mode' => 'externe'])->assertStatus(422)->assertJsonPath('code', 'AUCUNE_COMMISSION');
        // Le type commission ne passe pas par le paiement générique.
        $this->postJson($this->url($store, "employes/{$aicha}/paiements"), ['type' => 'commission', 'montant' => 100, 'mode' => 'externe'])->assertStatus(422);

        $this->postJson($this->url($store, "ventes/{$sale}/annuler"), ['motif' => 'Erreur', 'caisse_id' => $register->id])->assertOk();
        $this->getJson($this->url($store, 'commissions'))->assertJsonPath('donnees.0.du', '-1000.00');
    }

    public function test_the_management_report_has_a_salon_section(): void
    {
        ['store' => $store, 'register' => $register, 'aicha' => $aicha, 'manucure' => $manucure, 'client' => $client] = $this->salon();
        $rdv = $this->postJson($this->url($store, 'rendez-vous'), ['client_id' => $client, 'employe_id' => $aicha, 'service_id' => $manucure, 'debut_le' => CarbonImmutable::now()->subHours(3)->toIso8601String()])->assertStatus(201)->json('donnees.id');
        $this->postJson($this->url($store, "rendez-vous/{$rdv}/encaisser"), ['caisse_id' => $register->id])->assertOk();
        $other = $this->postJson($this->url($store, 'rendez-vous'), ['nom_client' => 'Passante', 'service_id' => $manucure, 'debut_le' => CarbonImmutable::now()->subHours(2)->toIso8601String()])->json('donnees.id');
        $this->postJson($this->url($store, "rendez-vous/{$other}/statut"), ['statut' => 'absent'])->assertOk();

        $salon = $this->getJson($this->url($store, 'rapports/gestion?periode=ce_mois'))->assertOk()->json('donnees.salon');
        $this->assertSame(2, $salon['rendez_vous']);
        $this->assertSame(50.0, (float) $salon['taux_absence']);
        $this->assertSame('5000.00', $salon['chiffre_affaires_prestations']);
        $this->assertSame('Manucure', $salon['prestations_plus_demandees'][0]['nom']);
        $this->assertSame('Aïcha', $salon['par_employe'][0]['nom']);
        $this->assertSame('500.00', $salon['commissions_dues']);
        $this->assertSame(1, $salon['clients']['nouveaux']);
    }
}

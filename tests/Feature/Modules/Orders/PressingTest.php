<?php

namespace Tests\Feature\Modules\Orders;

use App\Models\User;
use App\Modules\Appointments\Models\ServiceConsumption;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Orders\Models\OrderHistory;
use App\Modules\Tenancy\Models\Store;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Pressing : réception pièce par pièce, suivi, paiement ≠ remise, remise partielle, consommation, rapports. */
class PressingTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private const FEATURES = ['services', 'commandes', 'clients', 'employes', 'ventes', 'caisse', 'rapports', 'rapports_gestion', 'produits', 'stock', 'consommables', 'articles_depot'];

    /** @return array{owner: User, store: Store, register: CashRegister, lavage: int, client: int, lessive: int, awa: int} */
    private function pressing(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(self::FEATURES);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson($this->url($store, "caisses/{$register->id}/sessions"), ['montant_ouverture' => 20000])->assertStatus(201);

        $lessive = $this->postJson($this->url($store, 'produits'), ['nom' => 'Lessive', 'unite' => 'kg', 'prix_achat' => 1000, 'vente_detail_active' => true, 'prix_detail' => 1500])->assertStatus(201)->json('donnees.id');
        $this->postJson($this->url($store, "stocks/{$lessive}/mouvements"), ['type' => 'initial', 'quantite' => 10])->assertStatus(201);
        $lavage = $this->postJson($this->url($store, 'services'), [
            'nom' => 'Lavage et repassage', 'prix' => 500, 'unite_tarif' => 'piece', 'delai_heures' => 48, 'consignes' => 'Vérifier les poches',
            'tarifs_articles' => [['type_article' => 'Chemise', 'prix' => 500], ['type_article' => 'Costume', 'prix' => 2500]],
            'consommables' => [['produit_id' => $lessive, 'quantite' => 0.05]],
        ])->assertStatus(201)->assertJsonPath('donnees.unite_tarif', 'piece')->assertJsonCount(2, 'donnees.tarifs_articles')->json('donnees.id');
        $client = $this->postJson($this->url($store, 'clients'), ['nom' => 'Koffi', 'telephone' => '0101010101', 'instructions_remise' => 'Appeler avant'])->assertStatus(201)->assertJsonPath('donnees.instructions_remise', 'Appeler avant')->json('donnees.id');
        $awa = $this->postJson($this->url($store, 'employes'), ['nom' => 'Awa'])->json('donnees.id');

        return compact('owner', 'store', 'register', 'lavage', 'client', 'lessive', 'awa');
    }

    private function url(Store $store, string $path): string
    {
        return "/api/boutiques/{$store->id}/{$path}";
    }

    /** 3 chemises (pièces décrites pour 2) + 1 costume. */
    private function receive(Store $store, int $lavage, int $client, ?int $awa = null): array
    {
        return $this->postJson($this->url($store, 'commandes'), [
            'type' => 'depot', 'client_id' => $client, 'date_promise' => now()->addDays(2)->setTime(18, 30)->toIso8601String(), 'responsable_id' => $awa,
            'lignes' => [
                ['service_id' => $lavage, 'quantite' => 3, 'type_article' => 'Chemise', 'articles' => [
                    ['couleur' => 'Blanche', 'etat' => 'Bon', 'defauts' => 'Tache de café au col'],
                    ['couleur' => 'Bleue', 'marque' => 'Zara'],
                    ['couleur' => 'Rayée', 'instructions' => 'Pas d’amidon'],
                ]],
                ['service_id' => $lavage, 'quantite' => 1, 'type_article' => 'Costume'],
            ],
        ])->assertStatus(201)->json('donnees');
    }

    public function test_the_pressing_domain_gets_its_tools(): void
    {
        $this->seed(FeatureSeeder::class);
        $store = Store::factory()->create(['domaine_activite_id' => BusinessDomain::where('slug', 'pressing')->value('id')]);
        foreach (['articles_depot', 'produits', 'stock', 'fournisseurs', 'depenses', 'equipements', 'rapports_gestion', 'consommables'] as $slug) {
            $this->assertTrue(app(FeatureGate::class)->allows($store, $slug), $slug);
        }
        $restaurant = Store::factory()->create(['domaine_activite_id' => BusinessDomain::where('slug', 'restaurant')->value('id')]);
        $this->assertFalse(app(FeatureGate::class)->allows($restaurant, 'articles_depot'));
    }

    public function test_a_drop_off_is_received_piece_by_piece_with_grid_prices_and_codes(): void
    {
        ['store' => $store, 'lavage' => $lavage, 'client' => $client, 'awa' => $awa] = $this->pressing();
        $order = $this->receive($store, $lavage, $client, $awa);

        $this->assertSame('4000.00', $order['total']);
        $this->assertCount(4, $order['articles']);
        $this->assertSame('Tache de café au col', $order['articles'][0]['defauts']);
        $this->assertSame('Costume', $order['articles'][3]['type_article']);
        $this->assertSame('non_paye', $order['paiement']['statut']);
        $this->assertSame('Awa', $order['responsable']['nom']);
        $code = $order['articles'][1]['code'];

        // Recherche par code de pièce et par référence.
        $this->getJson($this->url($store, "commandes-articles?code={$code}"))->assertOk()->assertJsonPath('donnees.commande_id', $order['id']);
        $this->getJson($this->url($store, "commandes?recherche={$code}"))->assertOk()->assertJsonCount(1, 'donnees');
        $this->getJson($this->url($store, "commandes?recherche={$order['reference']}"))->assertJsonCount(1, 'donnees');
    }

    public function test_processing_is_tracked_with_history_and_consumes_products_once(): void
    {
        ['store' => $store, 'register' => $register, 'lavage' => $lavage, 'client' => $client, 'lessive' => $lessive, 'awa' => $awa] = $this->pressing();
        $order = $this->receive($store, $lavage, $client);
        $article = $order['articles'][0]['id'];

        $this->putJson($this->url($store, "commandes/{$order['id']}/articles/{$article}"), ['employe_id' => $awa, 'statut' => 'en_traitement'])->assertOk();
        $this->postJson($this->url($store, "commandes/{$order['id']}/statut"), ['statut' => 'en_preparation'])->assertOk();
        $this->postJson($this->url($store, "commandes/{$order['id']}/statut"), ['statut' => 'controle'])->assertOk()->assertJsonPath('donnees.articles.0.statut', 'controle');
        // « Remise » n'est pas un simple statut.
        $this->postJson($this->url($store, "commandes/{$order['id']}/statut"), ['statut' => 'servie'])->assertStatus(422)->assertJsonPath('code', 'REMISE_REQUISE');

        // Prête : 4 pièces × 0,05 kg prévus ; corrigé à 0,3 kg réellement utilisés.
        $this->getJson($this->url($store, "commandes/{$order['id']}/consommations"))->assertJsonPath('donnees.0.quantite', '0.200');
        $ready = $this->postJson($this->url($store, "commandes/{$order['id']}/statut"), ['statut' => 'prete', 'consommations' => [['produit_id' => $lessive, 'quantite' => 0.3]]])
            ->assertOk()->assertJsonPath('donnees.consommation_faite', true)->json('donnees');
        $this->assertSame('pret', $ready['articles'][3]['statut']);
        $this->getJson($this->url($store, "stocks/{$lessive}"))->assertJsonPath('donnees.quantite', '9.700');

        // Retour en traitement puis de nouveau prête : pas de seconde déduction.
        $this->postJson($this->url($store, "commandes/{$order['id']}/statut"), ['statut' => 'en_preparation'])->assertOk();
        $this->postJson($this->url($store, "commandes/{$order['id']}/statut"), ['statut' => 'prete'])->assertOk();
        $this->getJson($this->url($store, "stocks/{$lessive}"))->assertJsonPath('donnees.quantite', '9.700');
        $this->assertSame(1, ServiceConsumption::query()->whereNotNull('commande_id')->count());

        // Historique : qui, quand, de quoi vers quoi.
        $history = $this->getJson($this->url($store, "commandes/{$order['id']}"))->json('donnees.historique');
        $this->assertTrue(collect($history)->contains(fn ($e) => $e['evenement'] === 'statut' && $e['de'] === 'controle' && $e['vers'] === 'prete' && $e['par'] !== null));
        $this->assertTrue(collect($history)->contains(fn ($e) => $e['evenement'] === 'affectation'));

        // Payer à la caisse ne déduit rien de plus.
        $this->postJson($this->url($store, "commandes/{$order['id']}/encaisser"), ['caisse_id' => $register->id])->assertOk()
            ->assertJsonPath('donnees.statut', 'prete')->assertJsonPath('donnees.paiement.statut', 'paye');
        $this->getJson($this->url($store, "stocks/{$lessive}"))->assertJsonPath('donnees.quantite', '9.700');
    }

    public function test_partial_payments_and_partial_handovers_keep_an_exact_balance(): void
    {
        ['store' => $store, 'register' => $register, 'lavage' => $lavage, 'client' => $client] = $this->pressing();
        $order = $this->receive($store, $lavage, $client);
        $id = $order['id'];

        $this->postJson($this->url($store, "commandes/{$id}/acompte"), ['montant' => 1000, 'caisse_id' => $register->id])->assertOk()
            ->assertJsonPath('donnees.paiement.paye', '1000.00')->assertJsonPath('donnees.paiement.solde', '3000.00')->assertJsonPath('donnees.paiement.statut', 'partiel');

        // Pas prêtes : remise refusée.
        $this->postJson($this->url($store, "commandes/{$id}/remise"), ['article_ids' => [$order['articles'][0]['id']]])->assertStatus(422)->assertJsonPath('code', 'ARTICLE_NON_PRET');

        foreach ($order['articles'] as $a) {
            $this->putJson($this->url($store, "commandes/{$id}/articles/{$a['id']}"), ['statut' => 'pret'])->assertOk();
        }
        // Toutes les pièces prêtes → la commande l'est.
        $this->getJson($this->url($store, "commandes/{$id}"))->assertJsonPath('donnees.statut', 'prete');

        // Remise partielle de 2 chemises avec 1 000 payés.
        $partial = $this->postJson($this->url($store, "commandes/{$id}/remise"), [
            'article_ids' => [$order['articles'][0]['id'], $order['articles'][1]['id']],
            'paiement' => ['montant' => 1000, 'caisse_id' => $register->id],
        ])->assertOk()->json('donnees');
        $this->assertSame('prete', $partial['statut']);
        $this->assertSame(2, collect($partial['articles'])->where('statut', 'remis')->count());
        $this->assertSame('2000.00', $partial['paiement']['solde']);
        $this->assertCount(1, $partial['remises']);

        // Dernière remise sans tout payer : refusée (solde exigé par défaut).
        $this->postJson($this->url($store, "commandes/{$id}/remise"), ['caisse_id' => $register->id])->assertStatus(422)->assertJsonPath('code', 'SOLDE_A_REGLER');
        // Avec le solde : réglée et close.
        $final = $this->postJson($this->url($store, "commandes/{$id}/remise"), ['paiement' => ['montant' => 2000, 'caisse_id' => $register->id]])->assertOk()->json('donnees');
        $this->assertSame('payee', $final['statut']);
        $this->assertSame('paye', $final['paiement']['statut']);
        $this->assertNotNull($final['remise_le']);
        $this->getJson($this->url($store, "ventes/{$final['vente_id']}"))->assertJsonPath('donnees.total', '4000.00');
        // Encaissé = 1 000 + 1 000 + 2 000.
        $this->assertEquals(4000, (float) OrderHistory::query()->where('evenement', 'paiement')->sum('montant'));
    }

    public function test_handover_with_deferred_payment_goes_to_the_customer_account(): void
    {
        ['store' => $store, 'register' => $register, 'lavage' => $lavage, 'client' => $client] = $this->pressing();
        $order = $this->receive($store, $lavage, $client);
        $this->postJson($this->url($store, "commandes/{$order['id']}/acompte"), ['montant' => 500, 'caisse_id' => $register->id])->assertOk();
        $this->postJson($this->url($store, "commandes/{$order['id']}/statut"), ['statut' => 'prete'])->assertOk();

        // Un employé (sans gestion des crédits) ne peut pas reporter le solde.
        $cashier = User::factory()->create();
        $this->postJson($this->url($store, 'membres'), ['email' => $cashier->email, 'role' => 'employe'])->assertStatus(201);
        Sanctum::actingAs($cashier);
        $this->postJson($this->url($store, "commandes/{$order['id']}/remise"), ['caisse_id' => $register->id, 'differer_paiement' => true])->assertStatus(422)->assertJsonPath('code', 'PAIEMENT_DIFFERE_INTERDIT');

        Sanctum::actingAs(User::query()->orderBy('id')->firstOrFail());
        $done = $this->postJson($this->url($store, "commandes/{$order['id']}/remise"), ['caisse_id' => $register->id, 'differer_paiement' => true])->assertOk()->json('donnees');
        $this->assertSame('payee', $done['statut']);
        $this->assertSame('credit', $done['paiement']['statut']);
        $this->assertSame('3500.00', $done['paiement']['solde']);
        $this->getJson($this->url($store, "clients/{$client}/compte"))->assertJsonPath('meta.solde', '3500.00');

        // Réglage : solde non exigé → le report devient possible pour tous.
        $this->putJson($this->url($store, 'parametres/pressing'), ['solde_avant_remise' => false])->assertOk();
        $this->getJson($this->url($store, 'parametres/pressing'))->assertJsonPath('donnees.solde_avant_remise', false);
    }

    public function test_cancellation_creates_no_charge_nor_consumption_and_late_orders_are_flagged(): void
    {
        ['store' => $store, 'register' => $register, 'lavage' => $lavage, 'client' => $client, 'lessive' => $lessive] = $this->pressing();
        $order = $this->receive($store, $lavage, $client);
        $this->postJson($this->url($store, "commandes/{$order['id']}/annuler"), ['motif' => 'Client a changé d’avis'])->assertOk()->assertJsonPath('donnees.articles.0.statut', 'annule');
        $this->getJson($this->url($store, "stocks/{$lessive}"))->assertJsonPath('donnees.quantite', '10.000');
        $this->assertSame(0, ServiceConsumption::query()->count());

        $late = $this->postJson($this->url($store, 'commandes'), ['type' => 'depot', 'client_id' => $client, 'date_promise' => now()->subHour()->toIso8601String(), 'lignes' => [['service_id' => $lavage, 'quantite' => 1]]])
            ->assertStatus(201)->assertJsonPath('donnees.en_retard', true)->json('donnees');
        $this->getJson($this->url($store, 'commandes?en_retard=1'))->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.id', $late['id']);
        $this->artisan('notifications:depots')->assertSuccessful();
        $this->assertNotNull($register);
    }

    public function test_delivery_and_reports_distinguish_billed_from_collected(): void
    {
        ['store' => $store, 'register' => $register, 'lavage' => $lavage, 'client' => $client, 'awa' => $awa] = $this->pressing();
        $order = $this->postJson($this->url($store, 'commandes'), ['type' => 'livraison', 'client_id' => $client, 'adresse_livraison' => 'Cocody', 'lignes' => [['service_id' => $lavage, 'quantite' => 2, 'type_article' => 'Chemise']]])
            ->assertStatus(201)->assertJsonPath('donnees.statut_livraison', 'a_livrer')->json('donnees');
        $this->putJson($this->url($store, "commandes/{$order['id']}"), ['livreur_id' => $awa, 'statut_livraison' => 'en_livraison'])->assertOk()->assertJsonPath('donnees.livreur.nom', 'Awa');
        $this->postJson($this->url($store, "commandes/{$order['id']}/acompte"), ['montant' => 400, 'caisse_id' => $register->id])->assertOk();
        $this->postJson($this->url($store, "commandes/{$order['id']}/statut"), ['statut' => 'prete'])->assertOk();

        $dashboard = $this->getJson($this->url($store, 'rapports/tableau-de-bord?periode=aujourdhui'))->assertOk()->json('donnees.pressing');
        $this->assertSame(1, $dashboard['commandes_recues']);
        $this->assertSame(1, $dashboard['pretes']);
        $this->assertSame('1000.00', $dashboard['facture']);
        $this->assertSame('400.00', $dashboard['encaisse']);
        $this->assertSame('600.00', $dashboard['soldes_a_recouvrer']);

        $this->postJson($this->url($store, "commandes/{$order['id']}/remise"), ['mode' => 'livraison', 'paiement' => ['montant' => 600, 'caisse_id' => $register->id]])->assertOk()
            ->assertJsonPath('donnees.statut_livraison', 'livree')->assertJsonPath('donnees.statut', 'payee');
        $report = $this->getJson($this->url($store, 'rapports/gestion?periode=aujourdhui'))->assertOk()->json('donnees.pressing');
        $this->assertSame(1, $report['remises']);
        $this->assertSame('1000.00', $report['encaisse']);
        $this->assertSame('Lavage et repassage — Chemise', $report['prestations_plus_demandees'][0]['nom']);
    }

    public function test_another_store_cannot_reach_the_drop_off(): void
    {
        ['store' => $store, 'lavage' => $lavage, 'client' => $client] = $this->pressing();
        $order = $this->receive($store, $lavage, $client);
        ['store' => $other] = $this->createStoreWithFeatures(self::FEATURES);
        $this->getJson($this->url($other, "commandes/{$order['id']}"))->assertNotFound();
        $this->putJson($this->url($other, "commandes/{$order['id']}/articles/{$order['articles'][0]['id']}"), ['statut' => 'pret'])->assertNotFound();
        $this->getJson($this->url($other, "commandes-articles?code={$order['articles'][0]['code']}"))->assertNotFound();
    }
}

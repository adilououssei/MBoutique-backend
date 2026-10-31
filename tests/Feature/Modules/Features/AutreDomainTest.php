<?php

namespace Tests\Feature\Modules\Features;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\Feature;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Tenancy\Models\Store;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Domaine « Autre » : fiche activité, options activables (abonnement, dépendances), vente au temps, facturé ≠ encaissé. */
class AutreDomainTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{owner: User, store: Store} */
    private function autre(): array
    {
        $this->seed(FeatureSeeder::class);
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $businessId = $this->postJson('/api/entreprises', ['nom' => 'Imprimerie Koné'])->assertStatus(201)->json('donnees.id');
        $storeId = $this->postJson("/api/entreprises/{$businessId}/boutiques", ['nom' => 'Copie Express', 'domaine_activite_id' => BusinessDomain::where('slug', 'autre')->value('id')])
            ->assertStatus(201)->json('donnees.id');

        return ['owner' => $owner, 'store' => Store::findOrFail($storeId)];
    }

    private function url(Store $store, string $path): string
    {
        return "/api/boutiques/{$store->id}/{$path}";
    }

    public function test_an_autre_store_describes_its_activity(): void
    {
        ['store' => $store] = $this->autre();
        $this->putJson($this->url($store, 'parametres/activite'), ['nom' => 'Photocopie et impression', 'secteur' => 'impression', 'offre' => 'les_deux', 'unites' => ['page', 'heure']])
            ->assertOk()->assertJsonPath('donnees.offre', 'les_deux')->assertJsonPath('donnees.unites.1', 'heure');
        $this->assertSame('Photocopie et impression', $store->fresh()->parametres['activite']['nom']);
        $this->putJson($this->url($store, 'parametres/activite'), ['nom' => 'X', 'offre' => 'tout'])->assertStatus(422);
    }

    public function test_options_follow_defaults_dependencies_and_the_subscription(): void
    {
        ['store' => $store] = $this->autre();
        $gate = app(FeatureGate::class);
        $this->assertTrue($gate->allows($store, 'services'));
        $this->assertTrue($gate->allows($store, 'depenses'));
        $this->assertFalse($gate->allows($store, 'stock'));
        $this->assertFalse($gate->allows($store, 'ordonnances'), 'les modules spécialisés ne sont pas proposés');

        $options = collect($this->getJson($this->url($store, 'parametres/fonctionnalites'))->assertOk()->json('donnees'))->keyBy('slug');
        $this->assertTrue($options->has('stock') && $options->has('commandes') && $options->has('produits'));
        $this->assertFalse($options->has('ventes'), 'ventes et caisse restent obligatoires');
        $this->assertContains('produits', array_column($options['stock']['depend_de'], 'slug'));

        // Stock activé ; sans produits, il ne s'applique pas (dépendance).
        $this->putJson($this->url($store, 'parametres/fonctionnalites'), ['fonctionnalites' => ['stock' => true]])->assertOk();
        $this->assertTrue(app(FeatureGate::class)->allows($store->fresh(), 'stock'));
        $this->putJson($this->url($store, 'parametres/fonctionnalites'), ['fonctionnalites' => ['produits' => false]])->assertOk();
        $this->assertFalse(app(FeatureGate::class)->allows($store->fresh(), 'stock'));

        // Abonnement qui ne liste pas les fournisseurs : activation refusée.
        $plan = Plan::factory()->create();
        $plan->features()->attach(Feature::query()->whereIn('slug', ['produits', 'services', 'ventes', 'caisse', 'clients', 'rapports', 'stock'])->pluck('id'));
        $store->business->subscription()?->delete();
        $subscription = new Subscription(['forfait_id' => $plan->id]);
        $subscription->forceFill(['entreprise_id' => $store->entreprise_id])->save();
        $options = collect($this->getJson($this->url($store, 'parametres/fonctionnalites'))->json('donnees'))->keyBy('slug');
        $this->assertFalse($options['fournisseurs']['inclus_abonnement']);
        $this->putJson($this->url($store, 'parametres/fonctionnalites'), ['fonctionnalites' => ['fournisseurs' => true]])->assertStatus(422)->assertJsonPath('code', 'OPTION_NON_INCLUSE');
    }

    public function test_hourly_services_sell_in_fractions_with_partial_payment_and_the_report_splits_billed_and_collected(): void
    {
        ['store' => $store] = $this->autre();
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson($this->url($store, "caisses/{$register->id}/sessions"), ['montant_ouverture' => 0])->assertStatus(201);
        $this->putJson($this->url($store, 'parametres/fonctionnalites'), ['fonctionnalites' => ['rapports_gestion' => true]])->assertOk();

        $course = $this->postJson($this->url($store, 'services'), ['nom' => 'Cours particulier', 'prix' => 4000, 'unite_tarif' => 'heure'])->assertStatus(201)->assertJsonPath('donnees.unite_tarif', 'heure')->json('donnees.id');
        $book = $this->postJson($this->url($store, 'produits'), ['nom' => 'Cahier', 'vente_detail_active' => true, 'prix_detail' => 1000])->assertStatus(201)->json('donnees.id');
        $client = $this->postJson($this->url($store, 'clients'), ['nom' => 'Aminata'])->json('donnees.id');

        // 1,5 heure + 1 cahier = 7 000 ; 3 000 payés, le reste à crédit.
        $sale = $this->postJson($this->url($store, 'ventes/encaisser'), [
            'caisse_id' => $register->id, 'client_id' => $client, 'mode_paiement' => 'credit', 'acompte' => 3000,
            'lignes' => [['service_id' => $course, 'quantite' => 1.5], ['produit_id' => $book, 'mode_prix' => 'detail', 'quantite' => 1]],
        ])->assertStatus(201)->assertJsonPath('donnees.total', '7000.00')->json('donnees');
        $this->getJson($this->url($store, "clients/{$client}/compte"))->assertJsonPath('meta.solde', '4000.00');

        $billing = $this->getJson($this->url($store, 'rapports/gestion?periode=aujourdhui'))->assertOk()->json('donnees.ventes.facturation');
        $this->assertSame('7000.00', $billing['facture']);
        $this->assertSame('6000.00', $billing['prestations']);
        $this->assertSame('1000.00', $billing['produits']);
        $this->assertSame('4000.00', $billing['mis_a_credit']);
        $this->assertSame('3000.00', $billing['encaisse']);
        $this->assertSame('4000.00', $billing['reste_du_clients']);
        $this->assertNotNull($sale['id']);
    }

    public function test_activity_settings_stay_inside_the_store_and_need_the_owner(): void
    {
        ['store' => $store] = $this->autre();
        // Personne extérieure : la boutique n'existe pas pour elle.
        Sanctum::actingAs(User::factory()->create());
        $this->putJson($this->url($store, 'parametres/activite'), ['nom' => 'Intrus', 'offre' => 'services'])->assertNotFound();
        // Employé de la boutique : pas le droit de configurer.
        Sanctum::actingAs(User::query()->orderBy('id')->firstOrFail());
        $employee = User::factory()->create();
        $this->postJson($this->url($store, 'membres'), ['email' => $employee->email, 'role' => 'employe'])->assertStatus(201);
        Sanctum::actingAs($employee);
        $this->putJson($this->url($store, 'parametres/activite'), ['nom' => 'Intrus', 'offre' => 'services'])->assertStatus(403);
        $this->putJson($this->url($store, 'parametres/fonctionnalites'), ['fonctionnalites' => ['stock' => true]])->assertStatus(403);
    }
}

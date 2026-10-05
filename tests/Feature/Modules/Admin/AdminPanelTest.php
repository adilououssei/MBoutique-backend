<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\Feature;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Tenancy\Models\Business;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Interface d'administration de la plateforme (Blade) — docs/permissions.md §6. */
class AdminPanelTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['email' => 'admin@mboutique.test', 'password' => 'secret-pass']);
        $admin->forceFill(['est_admin_plateforme' => true])->save();

        return $admin;
    }

    public function test_the_login_page_is_the_site_root(): void
    {
        $this->get('/')->assertOk()->assertSee('Connectez-vous', false)->assertSee('admin-assets/admin.css', false);

        // Un dossier public/admin masquerait les routes /admin (serveur web).
        $this->assertDirectoryDoesNotExist(public_path('admin'));
    }

    public function test_only_platform_admins_can_log_in(): void
    {
        $this->admin();
        $merchant = User::factory()->create(['email' => 'boutiquier@test.com', 'password' => 'secret-pass']);

        $this->post('/', ['email' => $merchant->email, 'mot_de_passe' => 'secret-pass'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/', ['email' => 'admin@mboutique.test', 'mot_de_passe' => 'mauvais'])->assertSessionHasErrors('email');

        $this->post('/', ['email' => 'admin@mboutique.test', 'mot_de_passe' => 'secret-pass'])->assertRedirect(route('admin.tableau-de-bord'));
        $this->assertAuthenticated();
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_admin_pages_render(): void
    {
        ['store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $plan = Plan::factory()->create(['nom' => 'Pro']);
        $domain = BusinessDomain::query()->firstOrFail();
        $this->actingAs($this->admin());

        foreach ([
            route('admin.tableau-de-bord'),
            route('admin.entreprises.index'),
            route('admin.entreprises.index', ['recherche' => 'Boutique', 'statut' => 'active']),
            route('admin.entreprises.show', $store->entreprise_id),
            route('admin.boutiques.index'),
            route('admin.utilisateurs.index', ['type' => 'admin']),
            route('admin.forfaits.index'),
            route('admin.forfaits.create'),
            route('admin.forfaits.edit', $plan),
            route('admin.domaines.index'),
            route('admin.domaines.show', $domain),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_a_merchant_session_cannot_reach_the_admin(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertRedirect(route('connexion'));
        $this->assertGuest();
    }

    public function test_suspending_a_business_blocks_its_stores_in_the_api(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        $business = Business::findOrFail($store->entreprise_id);

        $this->actingAs($this->admin())
            ->patch(route('admin.entreprises.statut', $business), ['statut' => 'suspendue'])
            ->assertSessionHas('succes');

        Sanctum::actingAs($owner);
        $this->getJson("/api/boutiques/{$store->id}/produits")->assertStatus(403)->assertJsonPath('code', 'BOUTIQUE_SUSPENDUE');

        $this->actingAs($this->admin2())->patch(route('admin.entreprises.statut', $business), ['statut' => 'active']);
        Sanctum::actingAs($owner);
        $this->getJson("/api/boutiques/{$store->id}/produits")->assertOk();
    }

    public function test_deactivating_a_store_blocks_it(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);

        $this->actingAs($this->admin())->patch(route('admin.boutiques.statut', $store), ['statut' => 'inactive']);

        Sanctum::actingAs($owner);
        $this->getJson("/api/boutiques/{$store->id}/produits")->assertStatus(403)->assertJsonPath('code', 'BOUTIQUE_SUSPENDUE');
        $this->assertSame('inactive', Store::findOrFail($store->id)->statut->value);
    }

    public function test_deactivating_a_user_revokes_their_mobile_sessions(): void
    {
        $merchant = User::factory()->create();
        $merchant->createToken('mobile');
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.utilisateurs.statut', $merchant), ['statut' => 'inactif'])->assertSessionHas('succes');

        $this->assertFalse($merchant->fresh()->isActive());
        $this->assertSame(0, $merchant->tokens()->count());

        // Pas d'auto-désactivation.
        $this->patch(route('admin.utilisateurs.statut', $admin), ['statut' => 'inactif'])->assertSessionHasErrors('statut');
        $this->assertTrue($admin->fresh()->isActive());
    }

    public function test_plans_and_subscriptions_are_managed_from_the_admin(): void
    {
        ['store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $feature = Feature::query()->where('slug', 'stock')->firstOrFail();
        $this->actingAs($this->admin());

        $this->post(route('admin.forfaits.store'), [
            'code' => 'pro', 'nom' => 'Pro', 'prix_mensuel' => 15000, 'max_boutiques' => 3,
            'fonctionnalites' => [$feature->id], 'actif' => '1',
        ])->assertRedirect(route('admin.forfaits.index'));

        $plan = Plan::query()->where('code', 'pro')->firstOrFail();
        $this->assertTrue($plan->actif);
        $this->assertSame([$feature->id], $plan->features()->pluck('fonctionnalites.id')->all());
        $this->assertNull($plan->max_produits_par_boutique);

        $this->put(route('admin.entreprises.abonnement', $store->entreprise_id), [
            'forfait_id' => $plan->id, 'statut' => 'essai', 'fin_essai_le' => now()->addDays(14)->toDateString(),
        ])->assertSessionHas('succes');

        $business = Business::with('subscription')->findOrFail($store->entreprise_id);
        $this->assertSame($plan->id, $business->subscription->forfait_id);
        $this->assertSame('essai', $business->subscription->statut->value);
    }

    public function test_domain_default_features_can_be_toggled(): void
    {
        ['store' => $store] = $this->createStoreWithFeatures(['produits', 'stock']);
        $domain = BusinessDomain::findOrFail($store->domaine_activite_id);
        $produits = Feature::query()->where('slug', 'produits')->firstOrFail();
        $this->actingAs($this->admin());

        $this->put(route('admin.domaines.update', $domain), ['nom' => 'Épicerie', 'fonctionnalites' => [$produits->id], 'actif' => '1'])
            ->assertSessionHas('succes');

        $this->assertSame('Épicerie', $domain->fresh()->nom);
        $this->assertEqualsCanonicalizing([$produits->id], $domain->domainFeatures()->where('active_par_defaut', true)->pluck('fonctionnalite_id')->all());
    }

    private function admin2(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['est_admin_plateforme' => true])->save();

        return $admin;
    }
}

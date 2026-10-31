<?php

namespace Tests\Feature\Modules\Admin;

use App\Models\User;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreUser;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Classement des fonctionnalités par métier, attribution à d'autres métiers
 * (avec dépendances) et vocabulaire du métier — un SaaS configuré par
 * l'admin, sans déploiement.
 */
class AdminFeatureCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['est_admin_plateforme' => true])->save();

        return $admin;
    }

    private function enabled(string $domainSlug): array
    {
        return DomainFeature::query()
            ->join('fonctionnalites', 'fonctionnalites.id', '=', 'fonctionnalites_domaine.fonctionnalite_id')
            ->where('domaine_activite_id', BusinessDomain::where('slug', $domainSlug)->value('id'))
            ->where('active_par_defaut', true)
            ->pluck('fonctionnalites.slug')->sort()->values()->all();
    }

    /** Un membre d'une boutique du métier donné, et l'URL de ses fonctionnalités. */
    private function storeOf(string $domainSlug): string
    {
        $store = Store::factory()->create(['domaine_activite_id' => BusinessDomain::where('slug', $domainSlug)->value('id')]);
        $user = User::factory()->create();
        StoreUser::factory()->for($store)->for($user)->create(['statut' => 'actif']);
        Sanctum::actingAs($user);

        return "/api/boutiques/{$store->id}/fonctionnalites";
    }

    public function test_features_are_classified_and_the_pages_render(): void
    {
        $this->seed(FeatureSeeder::class);
        $this->assertSame('boucherie', Feature::where('slug', 'decoupe')->value('categorie'));
        $this->assertSame('socle', Feature::where('slug', 'ventes')->value('categorie'));
        $this->assertNotNull(Feature::where('slug', 'stock')->value('description'));

        $this->actingAs($this->admin());
        $this->get(route('admin.fonctionnalites.index'))->assertOk()->assertSee('Boucherie')->assertSee('Découpe');
        $this->get(route('admin.fonctionnalites.edit', Feature::where('slug', 'decoupe')->value('id')))->assertOk()->assertSee('Stock');
        $this->get(route('admin.domaines.show', BusinessDomain::where('slug', 'boucherie')->value('id')))->assertOk()->assertSee('Textes du métier');
    }

    public function test_a_butchery_feature_added_to_another_domain_brings_its_dependencies_and_removal_cascades(): void
    {
        $this->seed(FeatureSeeder::class);
        $cutting = Feature::where('slug', 'decoupe')->firstOrFail();
        $restaurant = BusinessDomain::where('slug', 'restaurant')->firstOrFail();
        $butcher = BusinessDomain::where('slug', 'boucherie')->firstOrFail();
        $this->actingAs($this->admin());

        // Pressing (sans stock ni produits) reçoit la découpe : stock et produits suivent.
        $pressing = BusinessDomain::where('slug', 'pressing')->firstOrFail();
        $this->put(route('admin.fonctionnalites.update', $cutting), [
            'nom' => 'Découpe', 'categorie' => 'boucherie', 'actif' => '1',
            'domaines' => [$butcher->id, $restaurant->id, $pressing->id],
        ])->assertSessionHas('succes');
        $this->assertContains('decoupe', $this->enabled('pressing'));
        $this->assertContains('stock', $this->enabled('pressing'));
        $this->assertContains('produits', $this->enabled('pressing'));

        // Retirer « Stock » à la boucherie retire aussi ce qui en dépend (découpe, préparations, conservation).
        $stock = Feature::where('slug', 'stock')->firstOrFail();
        $this->put(route('admin.fonctionnalites.update', $stock), [
            'nom' => 'Stock', 'categorie' => 'commerce', 'actif' => '1',
            'domaines' => BusinessDomain::where('slug', '!=', 'boucherie')->pluck('id')->all(),
        ])->assertSessionHas('succes');
        $this->assertNotContains('stock', $this->enabled('boucherie'));
        $this->assertNotContains('decoupe', $this->enabled('boucherie'));
        $this->assertNotContains('conservation', $this->enabled('boucherie'));
        $this->assertContains('ventes', $this->enabled('boucherie'));
    }

    public function test_the_domain_page_adds_missing_dependencies_and_removal_is_immediate_for_stores(): void
    {
        $this->seed(FeatureSeeder::class);
        $grocery = BusinessDomain::where('slug', 'alimentation_generale')->firstOrFail();
        $features = Feature::query()->pluck('id', 'slug');
        $url = $this->storeOf('alimentation_generale');
        $resolved = fn () => collect($this->getJson($url)->json('donnees.fonctionnalites'))->pluck('activee', 'slug');
        $this->assertTrue($resolved()['fournisseurs']);

        // Seules « ventes » et « découpe » cochées : stock et produits sont ajoutés, fournisseurs retiré.
        $this->actingAs($this->admin());
        $this->put(route('admin.domaines.update', $grocery), [
            'nom' => 'Alimentation générale', 'actif' => '1',
            'fonctionnalites' => [$features['ventes'], $features['decoupe']],
            'vocabulaire' => ['produit' => 'Sucre en poudre 1 kg', 'unite' => 'piece'],
        ])->assertSessionHas('succes', fn ($message) => str_contains($message, 'Stock') && str_contains($message, 'Produits'));
        $this->assertSame(['decoupe', 'produits', 'stock', 'ventes'], $this->enabled('alimentation_generale'));

        // Côté application : effet immédiat, et le vocabulaire du métier est transmis.
        Sanctum::actingAs(StoreUser::query()->latest('id')->firstOrFail()->user);
        $after = $this->getJson($url)->assertJsonPath('donnees.domaine.vocabulaire.produit', 'Sucre en poudre 1 kg');
        $flags = collect($after->json('donnees.fonctionnalites'))->pluck('activee', 'slug');
        $this->assertFalse($flags['fournisseurs']);
        $this->assertTrue($flags['decoupe']);
    }

    public function test_butcher_vocabulary_is_seeded(): void
    {
        $this->seed(FeatureSeeder::class);
        $this->assertSame('Filet de bœuf', BusinessDomain::where('slug', 'boucherie')->firstOrFail()->vocabulaire['produit']);
        $this->assertSame('kg', BusinessDomain::where('slug', 'boucherie')->firstOrFail()->vocabulaire['unite']);

        $this->getJson($this->storeOf('boucherie'))->assertJsonPath('donnees.domaine.vocabulaire.unite', 'kg');
    }

    public function test_voice_creation_and_excel_import_are_reserved_to_catalogue_trades(): void
    {
        $this->seed(FeatureSeeder::class);

        foreach (['alimentation_generale', 'vetements', 'electronique', 'boucherie'] as $domain) {
            $this->assertContains('creation_vocale', $this->enabled($domain), $domain);
            $this->assertContains('import_produits', $this->enabled($domain), $domain);
        }
        foreach (['restaurant', 'pharmacie', 'coiffure', 'salon_beaute', 'atelier', 'pressing', 'autre'] as $domain) {
            $this->assertNotContains('creation_vocale', $this->enabled($domain), $domain);
            $this->assertNotContains('import_produits', $this->enabled($domain), $domain);
        }
        $this->assertSame('commerce', Feature::where('slug', 'import_produits')->value('categorie'));
    }
}

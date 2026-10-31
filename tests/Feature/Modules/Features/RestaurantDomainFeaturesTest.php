<?php

namespace Tests\Feature\Modules\Features;

use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Tenancy\Models\Store;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Restaurant : fonctionnalités de la salle, de la cuisine et de gestion — sans toucher aux autres métiers. */
class RestaurantDomainFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_DEFAULTS = ['categories', 'fournisseurs', 'conservation', 'depenses', 'equipements', 'rapports_gestion', 'supplements', 'recettes', 'cuisine', 'reservations'];

    private function enabledFor(string $domainSlug, string $featureSlug): bool
    {
        $store = Store::factory()->create(['domaine_activite_id' => BusinessDomain::where('slug', $domainSlug)->value('id')]);

        return app(FeatureGate::class)->allows($store, $featureSlug);
    }

    public function test_a_restaurant_gets_the_restaurant_features_and_a_grocery_does_not(): void
    {
        $this->seed(FeatureSeeder::class);

        foreach (self::NEW_DEFAULTS as $slug) {
            $this->assertTrue($this->enabledFor('restaurant', $slug), $slug);
        }
        $this->assertFalse($this->enabledFor('alimentation_generale', 'cuisine'));
        $this->assertFalse($this->enabledFor('boucherie', 'reservations'));
    }

    public function test_the_data_migration_enables_them_for_existing_restaurants_only(): void
    {
        $this->seed(FeatureSeeder::class);
        $restaurant = BusinessDomain::where('slug', 'restaurant')->value('id');
        $ids = Feature::whereIn('slug', self::NEW_DEFAULTS)->pluck('id');
        DomainFeature::where('domaine_activite_id', $restaurant)->whereIn('fonctionnalite_id', $ids)->delete();
        $before = DomainFeature::where('domaine_activite_id', '!=', $restaurant)->count();

        $migration = require base_path('app/Modules/Orders/Database/Migrations/2026_11_15_000001_add_restaurant_features.php');
        $migration->up();
        $migration->up(); // idempotente

        foreach (self::NEW_DEFAULTS as $slug) {
            $this->assertTrue($this->enabledFor('restaurant', $slug), $slug);
        }
        $this->assertSame($before, DomainFeature::where('domaine_activite_id', '!=', $restaurant)->count());
    }
}

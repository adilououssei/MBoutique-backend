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

/** Boucherie : les préparations commandées passent par Orders — sans toucher aux autres domaines. */
class ButcherDomainFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function enabledFor(string $domainSlug, string $featureSlug): bool
    {
        $store = Store::factory()->create(['domaine_activite_id' => BusinessDomain::where('slug', $domainSlug)->value('id')]);

        return app(FeatureGate::class)->allows($store, $featureSlug);
    }

    public function test_a_butcher_shop_gets_orders_by_default_but_a_grocery_does_not(): void
    {
        $this->seed(FeatureSeeder::class);

        $this->assertTrue($this->enabledFor('boucherie', 'commandes'));
        $this->assertFalse($this->enabledFor('boucherie', 'tables'));
        $this->assertFalse($this->enabledFor('alimentation_generale', 'commandes'));
    }

    public function test_the_data_migration_enables_orders_for_existing_butcher_shops_only(): void
    {
        $this->seed(FeatureSeeder::class);
        $orders = Feature::where('slug', 'commandes')->value('id');
        $butcher = BusinessDomain::where('slug', 'boucherie')->value('id');
        // État d'avant la migration : la boucherie n'avait pas les commandes.
        DomainFeature::where(['domaine_activite_id' => $butcher, 'fonctionnalite_id' => $orders])->delete();
        $before = DomainFeature::where('domaine_activite_id', '!=', $butcher)->count();
        $this->assertFalse($this->enabledFor('boucherie', 'commandes'));

        $migration = require base_path('app/Modules/Features/Database/Migrations/2026_10_30_000001_enable_orders_for_butcher_domain.php');
        $migration->up();
        $migration->up(); // idempotente

        $this->assertTrue($this->enabledFor('boucherie', 'commandes'));
        $this->assertSame(1, DomainFeature::where(['domaine_activite_id' => $butcher, 'fonctionnalite_id' => $orders])->count());
        $this->assertSame($before, DomainFeature::where('domaine_activite_id', '!=', $butcher)->count());
    }
}

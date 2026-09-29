<?php

namespace Tests\Feature\Modules\Features;

use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_feature_can_be_created(): void
    {
        $feature = Feature::factory()->create(['slug' => 'produits']);

        $this->assertDatabaseHas('fonctionnalites', ['slug' => 'produits', 'actif' => true]);
    }

    public function test_a_feature_can_be_deactivated(): void
    {
        $feature = Feature::factory()->create();

        $feature->update(['actif' => false]);

        $this->assertFalse($feature->fresh()->actif);
    }

    public function test_a_feature_can_be_associated_with_a_domain(): void
    {
        $domain = BusinessDomain::factory()->create();
        $feature = Feature::factory()->create();

        $domainFeature = DomainFeature::create([
            'domaine_activite_id' => $domain->id,
            'fonctionnalite_id' => $feature->id,
            'active_par_defaut' => true,
        ]);

        $this->assertCount(1, $domain->domainFeatures);
        $this->assertTrue($domainFeature->active_par_defaut);
    }

    public function test_a_feature_can_be_removed_from_a_domain(): void
    {
        $domain = BusinessDomain::factory()->create();
        $feature = Feature::factory()->create();
        $domainFeature = DomainFeature::create([
            'domaine_activite_id' => $domain->id,
            'fonctionnalite_id' => $feature->id,
            'active_par_defaut' => true,
        ]);

        $domainFeature->delete();

        $this->assertCount(0, $domain->domainFeatures()->get());
    }
}

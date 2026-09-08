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
        $feature = Feature::factory()->create(['slug' => 'products']);

        $this->assertDatabaseHas('features', ['slug' => 'products', 'is_active' => true]);
    }

    public function test_a_feature_can_be_deactivated(): void
    {
        $feature = Feature::factory()->create();

        $feature->update(['is_active' => false]);

        $this->assertFalse($feature->fresh()->is_active);
    }

    public function test_a_feature_can_be_associated_with_a_domain(): void
    {
        $domain = BusinessDomain::factory()->create();
        $feature = Feature::factory()->create();

        $domainFeature = DomainFeature::create([
            'business_domain_id' => $domain->id,
            'feature_id' => $feature->id,
            'is_default_enabled' => true,
        ]);

        $this->assertCount(1, $domain->domainFeatures);
        $this->assertTrue($domainFeature->is_default_enabled);
    }

    public function test_a_feature_can_be_removed_from_a_domain(): void
    {
        $domain = BusinessDomain::factory()->create();
        $feature = Feature::factory()->create();
        $domainFeature = DomainFeature::create([
            'business_domain_id' => $domain->id,
            'feature_id' => $feature->id,
            'is_default_enabled' => true,
        ]);

        $domainFeature->delete();

        $this->assertCount(0, $domain->domainFeatures()->get());
    }
}

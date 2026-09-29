<?php

namespace Tests\Feature\Modules\Features;

use App\Models\User;
use App\Modules\Features\Models\BusinessDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessDomainEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_active_domains(): void
    {
        BusinessDomain::factory()->create(['nom' => 'Coiffure', 'slug' => 'coiffure']);
        BusinessDomain::factory()->create(['nom' => 'Archivé', 'slug' => 'archive', 'actif' => false]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/domaines-activite')
            ->assertOk()
            ->assertJsonPath('succes', true)
            ->assertJsonCount(1, 'donnees')
            ->assertJsonPath('donnees.0.slug', 'coiffure');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/domaines-activite')->assertUnauthorized();
    }
}

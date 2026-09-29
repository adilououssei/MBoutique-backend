<?php

namespace Tests\Feature\Modules\Features;

use App\Models\User;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreUser;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * docs/feature-gate.md §"Feature vs Permission" — the two must never be
 * conflated. This registers a throwaway route using the exact middleware
 * chain a real business module route will use once one exists (Sales,
 * Products, ...), since no such route exists yet in this phase.
 */
class FeatureVersusPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'store', 'feature:ventes', 'permission:membres.gerer'])
            ->prefix('api')
            ->get('_test/feature-and-permission/boutiques/{store}', fn () => response()->json(['succes' => true]));
    }

    private function storeWithFeature(string $slug, bool $enabled): Store
    {
        $domain = BusinessDomain::factory()->create();
        $feature = Feature::factory()->create(['slug' => $slug]);
        DomainFeature::create(['domaine_activite_id' => $domain->id, 'fonctionnalite_id' => $feature->id, 'active_par_defaut' => $enabled]);

        return Store::factory()->create(['domaine_activite_id' => $domain->id]);
    }

    private function grantPermission(Store $store, User $user, string $permission): void
    {
        $tenantContext = app(TenantContextContract::class);
        $previous = $tenantContext->getStoreId();
        $tenantContext->setStoreId($store->id);

        try {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        } finally {
            $previous !== null ? $tenantContext->setStoreId($previous) : $tenantContext->clear();
        }
    }

    public function test_feature_disabled_blocks_access_even_with_the_permission(): void
    {
        $store = $this->storeWithFeature('ventes', enabled: false);
        $user = User::factory()->create();
        StoreUser::factory()->for($store)->for($user)->create(['statut' => 'actif']);
        $this->grantPermission($store, $user, 'membres.gerer');
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/_test/feature-and-permission/boutiques/{$store->id}");

        $response->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');
    }

    public function test_feature_enabled_but_missing_permission_still_blocks_access(): void
    {
        $store = $this->storeWithFeature('ventes', enabled: true);
        $user = User::factory()->create();
        StoreUser::factory()->for($store)->for($user)->create(['statut' => 'actif']);
        Sanctum::actingAs($user);
        // No permission granted at all: the feature exists for this
        // store, but this user individually may not use it.

        $response = $this->getJson("/api/_test/feature-and-permission/boutiques/{$store->id}");

        $response->assertStatus(403);
        $this->assertNotSame('FONCTIONNALITE_DESACTIVEE', $response->json('code'));
    }

    public function test_feature_enabled_and_permission_granted_allows_access(): void
    {
        $store = $this->storeWithFeature('ventes', enabled: true);
        $user = User::factory()->create();
        StoreUser::factory()->for($store)->for($user)->create(['statut' => 'actif']);
        $this->grantPermission($store, $user, 'membres.gerer');
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/_test/feature-and-permission/boutiques/{$store->id}");

        $response->assertStatus(200);
    }
}

<?php

namespace Tests\Feature\Modules\Catalog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/**
 * docs/catalog.md §"Authorization vs FeatureGate": these tests keep the
 * feature always enabled and vary only the permission, to isolate that
 * axis from FeatureGate (which has its own suite, CatalogFeatureGateTest).
 */
class CatalogAuthorizationTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    public function test_an_employee_can_view_products_but_not_create_one(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        $employee = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/members", ['email' => $employee->email, 'role' => 'employee'])
            ->assertStatus(201);

        Sanctum::actingAs($employee);

        $this->getJson("/api/stores/{$store->id}/products")->assertStatus(200);

        $this->postJson("/api/stores/{$store->id}/products", ['name' => 'Article', 'retail_enabled' => true, 'retail_price' => 100])
            ->assertStatus(403);
    }

    public function test_a_manager_can_create_a_product(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        $manager = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/members", ['email' => $manager->email, 'role' => 'manager'])
            ->assertStatus(201);

        Sanctum::actingAs($manager);

        $this->postJson("/api/stores/{$store->id}/products", ['name' => 'Article', 'retail_enabled' => true, 'retail_price' => 100])
            ->assertStatus(201);
    }

    public function test_a_user_with_no_membership_at_all_is_blocked_before_any_permission_check(): void
    {
        ['store' => $store] = $this->createStoreWithFeatures(['products']);
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);

        $this->getJson("/api/stores/{$store->id}/products")->assertStatus(404);
    }
}
